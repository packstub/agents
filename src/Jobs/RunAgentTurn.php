<?php

namespace Packstub\Agents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Packstub\Agents\Ai\Side\ClassifierAgent;
use Packstub\Agents\Ai\Side\TitleAgent;
use Packstub\Agents\Events\ProposalDecided;
use Packstub\Agents\Events\ToolCalled;
use Packstub\Agents\Events\TurnStarted;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRedactor;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\PageContext;
use Throwable;

/**
 * One turn of the chat, off the request: the agent streams the answer while
 * the job writes what it has so far to the turn row, which the page polls.
 * The person can stop it (the flag is checked between events; the partial
 * answer is stored with a marker), a provider failure keeps the question
 * with a Retry, and when the turn ends the next queued turn of the same
 * conversation is started. On the sync driver (chat.driver, or a sync queue
 * connection) the whole thing runs inside the request, as it did before —
 * nothing else changes.
 */
class RunAgentTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A turn is billed once: it is never retried by the queue. */
    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array{panel: ?string, guard: ?string, tenant: ?string, user: int|string|null, locale: ?string}  $runtime
     */
    public function __construct(public string $turnId, public array $runtime)
    {
        $this->timeout = AgentTurns::jobTimeout();
    }

    public function handle(AgentTurns $turns): void
    {
        try {
            $leave = AgentRuntime::enter($this->runtime);
        } catch (WorkspaceAccessDenied $denied) {
            $this->refuse($turns, $denied);

            return;
        }

        try {
            $turn = AgentTurn::query()->find($this->turnId);

            if (! $turn || ! $turns->claim($turn)) {
                return;
            }

            $this->run($turn, $turns);
        } finally {
            if (isset($turn)) {
                $turns->startNext($turn->conversation_id);
            }

            $leave();
        }
    }

    /**
     * The person is no longer a member of the workspace the turn was asked in (revoked between the request and
     * the worker): the turn ends failed with that line, without entering the workspace.
     */
    protected function refuse(AgentTurns $turns, WorkspaceAccessDenied $denied): void
    {
        $leave = AgentRuntime::enter(['tenant' => null] + $this->runtime);

        try {
            $turn = AgentTurn::query()->find($this->turnId);

            if ($turn && $turn->isOpen() && ($turn->status !== AgentTurn::PENDING || $turns->claim($turn))) {
                $turns->finish($turn, AgentTurn::FAILED, $denied->getMessage());
                $turns->startNext($turn->conversation_id);
            }
        } finally {
            $leave();
        }
    }

    /** The worker gave up on the job (timeout, lost process): the question keeps its Retry. */
    public function failed(?Throwable $exception): void
    {
        try {
            $leave = AgentRuntime::enter($this->runtime);
        } catch (WorkspaceAccessDenied $denied) {
            $this->refuse(app(AgentTurns::class), $denied);

            return;
        }

        try {
            $turn = AgentTurn::query()->find($this->turnId);

            if ($turn && $turn->isOpen()) {
                app(AgentTurns::class)->finish($turn, AgentTurn::FAILED, $exception?->getMessage() ?: __('The answer was interrupted.'));
                app(AgentTurns::class)->startNext($turn->conversation_id);
            }
        } finally {
            $leave();
        }
    }

    /**
     * The records a question mentioned, summarized for the model, or null without any.
     *
     * @param  list<array{ref: string, label: string}>  $mentions
     */
    public static function mentionsBlock(array $mentions): ?string
    {
        $lines = [];

        foreach ($mentions as $mention) {
            $context = is_array($mention) && isset($mention['ref']) ? PageContext::resolve((string) $mention['ref']) : null;

            if ($context !== null) {
                $lines[] = '- @'.$context['label'].' ('.$mention['ref'].'): '.json_encode($context['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $lines === [] ? null : "Records the person mentioned in the question (\"@name\" refers to these):\n".implode("\n", $lines);
    }

    protected function run(AgentTurn $turn, AgentTurns $turns): void
    {
        $store = app(AgentConversationStore::class);
        $user = $turns->participant($turn);

        if (! $user) {
            $turns->finish($turn, AgentTurn::FAILED, __('The person who asked could not be found.'));

            return;
        }

        $input = $turn->prompt() ?? Decisions::from(collect($turn->decisions() ?? [])->map(
            fn (bool $approve) => $approve ? Decision::approve() : Decision::reject(AgentTurns::rejectionResult()),
        )->all());

        // The files the person attached to the question, as the provider reads them; the records they mentioned
        // ride with the question as their summaries (the stored question keeps the words as typed).
        $attachments = AgentAttachments::rehydrate($turn->input['attachments'] ?? []);

        if (is_string($input) && ($mentions = self::mentionsBlock((array) ($turn->input['mentions'] ?? []))) !== null) {
            $input .= "\n\n".$mentions;
        }

        if ($turn->decisions() !== null) {
            // laravel/ai 1.0 applies the decisions — and runs an approved tool — before the first step's middleware
            // sees the turn, so a resume the budget refuses (the assistant switched off, a limit reached) is stopped
            // here, before anything runs.
            if (($refusal = AgentBudget::refusal()) !== null) {
                $turns->finish($turn, AgentTurn::FAILED, $refusal, metrics: ['finish_reason' => 'refused']);

                return;
            }

            $pending = $store->pendingCalls($turn->conversation_id, $user);

            foreach ($turn->decisions() as $callId => $approved) {
                ProposalDecided::dispatch($turn, (string) $callId, (string) ($pending[$callId]['name'] ?? ''), (array) ($pending[$callId]['arguments'] ?? []), (bool) $approved);
            }
        }

        // Secrets and personal data never reach the page or the transcript (config `redact`): every snapshot the page
        // reads is redacted with the value under way held back, and the turn reports once what it replaced.
        $redactor = app(AgentRedactor::class)->collecting();

        $agent = Agents::agent($turn->context, $turn->model)->continue($turn->conversation_id, as: $user);
        $turns->snapshot($turn, null, __('Thinking…'));
        $store->answering($turn->message_id);
        TurnStarted::dispatch($turn);

        $buffer = '';
        $stopped = false;

        // The turn's record: what answered, what it cost, what it called, how long it took, how it ended.
        $startedAt = microtime(true);
        $usage = new TextUsage;
        $tools = [];
        $resolved = null;
        $answered = null; // the provider and model that took the turn, from the stream: a fallback when the first choice refused it
        $measure = function (string $reason) use ($startedAt, &$usage, &$tools, &$resolved, &$answered): array {
            return [
                'provider' => $answered['provider'] ?? $resolved['provider'] ?? null,
                'model_name' => $answered['model'] ?? $resolved['model'] ?? null,
                'usage' => $usage->toArray(),
                'tool_calls' => $tools,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'finish_reason' => $reason,
            ];
        };

        try {
            $resolved = AgentModels::resolve($turn->model);
            $provider = app(AiManager::class)->textProviderFor($agent, $resolved['provider']);
            // What no longer fits the history window is folded into the rolling summary by the provider's cheapest model.
            $store->summarizeWith(AgentConversationStore::providerSummarizer($provider));
            $store->cachingFor($provider);
            // The provider list: the first choice, then the failover providers (AGENT_FAILOVER), each with its own model.
            // laravel/ai moves down the list when a provider refuses the turn before anything streamed and fires
            // Laravel\Ai\Events\AgentFailedOver; the stream's start says who took it.
            $response = $agent->withModel($resolved['model'])->withModels($resolved['providers'])->stream($input, attachments: $attachments, provider: $resolved['providers']);

            $sinceWrite = 0;
            $lastCheck = 0.0;
            $status = __('Thinking…');
            $end = null;

            // The answer so far is written on every line (or every ~120 chars) and on every tool event, so the page
            // can render it as Markdown while it streams; Stop is a flag on the row, read whenever a snapshot is
            // written and at least a few times a second. Leaving the stream on a stop means laravel/ai never stores
            // the answer, so the partial one is stored below, with a marker.
            foreach ($response as $event) {
                $wrote = false;

                if ($event instanceof TextDelta) {
                    $buffer .= $event->delta;
                    $sinceWrite += strlen($event->delta);
                    $status = __('Writing…');
                    if (str_contains($event->delta, "\n") || $sinceWrite >= 120) {
                        $turns->snapshot($turn, $redactor->streaming($buffer), $status);
                        $sinceWrite = 0;
                        $wrote = true;
                    }
                } elseif ($event instanceof ToolCall) {
                    $tools[] = $event->toolCall->name;
                    $status = __(':tool…', ['tool' => Str::headline($event->toolCall->name)]);
                    $turns->snapshot($turn, $redactor->streaming($buffer), $status, $tools);
                    ToolCalled::dispatch($turn, $event->toolCall->id, $event->toolCall->name, $event->toolCall->arguments);
                    $wrote = true;
                } elseif ($event instanceof ToolResult && ! $event->preliminary) { // a sub-agent's progress is preliminary: not a result yet
                    $status = __('Thinking…');
                    if ($buffer !== '') {
                        $buffer .= "\n\n";
                    }
                    $turns->snapshot($turn, $redactor->streaming($buffer), $status);
                    $wrote = true;
                } elseif ($event instanceof ReasoningDelta) {
                    if ($status !== __('Reasoning…')) {
                        $status = __('Reasoning…');
                        $turns->snapshot($turn, $redactor->streaming($buffer), $status);
                        $wrote = true;
                    }
                } elseif ($event instanceof ProviderToolEvent) {
                    // A tool the provider runs itself (web search, file search): the status line says so while it works.
                    $working = self::providerToolStatus($event);
                    if ($event->status !== 'completed' && $event->status !== 'result_received' && $status !== $working) {
                        $status = $working;
                        $turns->snapshot($turn, $redactor->streaming($buffer), $status);
                        $wrote = true;
                    }
                } elseif ($event instanceof Error && ! $event->recoverable) {
                    // The step ends here; laravel/ai raises the error once the event is consumed (StreamErrorException)
                    // and records the failed turn with the steps it completed. Throwing from this side would skip that.
                    $status = __('Thinking…');
                } elseif ($event instanceof StreamEnd) {
                    $end = $event;
                    $usage = $usage->add($event->usage);
                } elseif ($event instanceof StreamStart) {
                    $answered ??= ['provider' => $event->provider, 'model' => $event->model];
                }

                if ($wrote || microtime(true) - $lastCheck >= 0.25) {
                    $lastCheck = microtime(true);
                    if ($turns->stopRequested($turn)) {
                        $stopped = true;
                        break;
                    }
                }
            }

            if ($stopped) {
                $buffer = $redactor->redact($buffer);
                if (trim($buffer) !== '') {
                    $store->storeStoppedAnswer($turn->conversation_id, $user, $agent::class, $buffer);
                }
                $turns->finish($turn, AgentTurn::STOPPED, text: $buffer, metrics: $measure('stopped'));

                return;
            }

            // The provider ended the answer early (its length limit, its filter, a dropped stream): what arrived is
            // stored as the answer; mark it so the page says so and offers Regenerate.
            if (trim($buffer) !== '' && ($reason = AgentTurns::cutShortReason($end)) !== null) {
                $store->markCutShort($turn->conversation_id, $reason);
            }

            // A fallback answered: the answer says so, and the title comes from the provider that is up.
            if ($answered !== null && $answered['provider'] !== $resolved['provider']) {
                $store->markAnsweredBy($turn->conversation_id, $answered['provider'], $answered['model']);
                $provider = app(AiManager::class)->textProviderFor($agent, $answered['provider']);
            }

            // With the assistant faked in a test the title is written only when TitleAgent is faked too: the question
            // stays the title, and the assistant's fake answers are all the assistant's.
            if (($turn->input['title'] ?? false) && $turn->prompt() !== null && TitleAgent::runsBeside($agent)) {
                $store->titleConversation($turn->conversation_id, $turn->prompt(), $provider);
            }

            $turns->finish($turn, AgentTurn::DONE, text: $redactor->redact($buffer), metrics: $measure($end?->reason ?? 'dropped'));

            // What the chat is about, how the person sounds, whether it is resolved (config `classify`): after the
            // turn ended, so the answer is not held up; with the assistant faked, only when the classifier is too.
            if ($turn->prompt() !== null && ClassifierAgent::runsBeside($agent)) {
                $store->classifyConversation($turn->conversation_id, $provider);
            }
        } catch (Throwable $e) {
            // A refusal by a middleware (a budget spent, a guard) is the turn's outcome, not an error to report.
            if (! $e instanceof TurnRefused) {
                report($e);
            }
            $turns->finish($turn, AgentTurn::FAILED, $e->getMessage(), text: $redactor->redact($buffer), metrics: $measure($e instanceof TurnRefused ? 'refused' : 'failed'));
        } finally {
            // The store is a request-scoped singleton and must not carry a turn's state into the next one.
            $store->answering(null);
            $store->summarizeWith(null);
            $redactor->collecting(false)->report(['turn' => $turn->id, 'conversation' => $turn->conversation_id], force: true);
        }
    }

    /** The status line while the provider runs one of its own tools: a web search, a search of the documents, or just work. */
    public static function providerToolStatus(ProviderToolEvent $event): string
    {
        $what = strtolower($event->type.' '.(is_string($event->data['name'] ?? null) ? $event->data['name'] : ''));

        return match (true) {
            str_contains($what, 'web_search') || str_contains($what, 'google_search') => __('Searching the web…'),
            str_contains($what, 'file_search') => __('Searching the knowledge base…'),
            default => __('Working…'),
        };
    }
}
