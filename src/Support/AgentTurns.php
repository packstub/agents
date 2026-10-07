<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Packstub\Agents\Events\TurnEnded;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentTurn;

/**
 * The turns of a conversation, from the composer to the stored answer.
 *
 * A turn is queued on its conversation; the oldest queued turn is handed to
 * the job as soon as no other turn of that conversation is pending or
 * running (a question is recorded in the transcript at that moment, not
 * before, so the order of the transcript is the order of the answers). The
 * RunAgentTurn job claims it, streams the answer into the row and marks how
 * it ended, then starts the next one. The page and the poll endpoint read the
 * rows; a turn whose job went quiet for longer than the job timeout is shown
 * as failed, with a Retry. A deferred turn is stored with its question and
 * waits, outside the line, until the person opens the conversation.
 */
class AgentTurns
{
    /** Queue a turn on a conversation and start it when nothing else runs there. */
    public function enqueue(string $conversationId, object $participant, array $input, ?string $messageId, string $model, ?string $context): AgentTurn
    {
        $runtime = AgentRuntime::capture();

        // A decision while another one waits (an answer that proposed two changes, decided one at a time): the
        // decisions join the waiting turn, which starts once every proposal of that answer has one.
        if (isset($input['decisions'])) {
            $held = AgentTurn::query()->forConversation($conversationId)->where('status', AgentTurn::QUEUED)
                ->where('participant_type', Conversation::participantType($participant))->where('participant_id', Conversation::participantKey($participant))
                ->orderBy('id')->get()->first(fn (AgentTurn $t) => $t->decisions() !== null);

            if ($held) {
                $held->forceFill(['input' => ['decisions' => [...$held->decisions(), ...$input['decisions']]]])->save();
                $this->startNext($conversationId);

                return $held->refresh();
            }
        }

        $turn = AgentTurn::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => Conversation::participantType($participant),
            'participant_id' => Conversation::participantKey($participant),
            'message_id' => $messageId,
            'status' => AgentTurn::QUEUED,
            'input' => $input,
            'model' => $model,
            'context' => $context,
            'panel' => $runtime['panel'],
            'guard' => $runtime['guard'],
            'tenant' => $runtime['tenant'] !== null ? (string) $runtime['tenant'] : null,
            'locale' => $runtime['locale'],
        ]);

        $this->startNext($conversationId);

        return $turn->refresh();
    }

    /**
     * Store a question with its turn held back — `deferred` — until the person opens the conversation: an app
     * that opens a chat for someone (a close to walk through, a summary to discuss) records what it asks now,
     * and the answer is produced, and billed, when they come to read it (startDeferred()). The question is
     * recorded at once, so the transcript shows it wherever the conversation is read; $input is enqueue()'s,
     * with a prompt. The conversation is titled by the provider once the first answer is in, unless an answer
     * already exists, the app titled it (startConversation(..., title: '…')) or $input['title'] says otherwise.
     */
    public function defer(string $conversationId, object $participant, array $input, string $model, ?string $context): AgentTurn
    {
        if (! is_string($input['prompt'] ?? null) || trim($input['prompt']) === '') {
            throw new InvalidArgumentException('A deferred turn needs a prompt.');
        }

        $runtime = AgentRuntime::capture();
        $store = app(AgentConversationStore::class);

        $input += ['title' => $this->untitled($conversationId, $input['prompt'])];

        $messageId = $store->storeQuestion(
            $conversationId,
            $participant,
            Agents::agentClass(),
            $input['prompt'],
            (array) ($input['attachments'] ?? []),
            array_filter([
                'mentions' => ($input['mentions'] ?? []) !== [] ? array_values((array) $input['mentions']) : null,
                'context' => filled($context) ? $context : null,
            ], fn ($v) => $v !== null),
        );

        return AgentTurn::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => Conversation::participantType($participant),
            'participant_id' => Conversation::participantKey($participant),
            'message_id' => $messageId,
            'status' => AgentTurn::DEFERRED,
            'input' => $input,
            'model' => $model,
            'context' => $context,
            'panel' => $runtime['panel'],
            'guard' => $runtime['guard'],
            'tenant' => $runtime['tenant'] !== null ? (string) $runtime['tenant'] : null,
            'locale' => $runtime['locale'],
        ])->refresh();
    }

    /**
     * Whether the conversation still carries the placeholder title (the first question, or nothing), so the
     * provider may title it once the first answer is in. An answer already stored, or a title the app gave
     * (startConversation(..., title: '…')), keeps the title as it is.
     */
    protected function untitled(string $conversationId, string $prompt): bool
    {
        if (ConversationMessage::query()->where('conversation_id', $conversationId)->where('role', 'assistant')->exists()) {
            return false;
        }

        $title = trim((string) Conversation::query()->whereKey($conversationId)->value('title'));

        return $title === '' || $title === Str::limit($prompt, 50, preserveWords: true);
    }

    /**
     * The person opened the conversation: its deferred turn (theirs) is queued and started, once — a lock on the
     * conversation keeps two tabs from starting it twice (Cache::lock, so the cache store must support locks) —
     * with who is acting and where captured now, on the surface they opened it from, and the budget checked at
     * this moment rather than when the question was stored. Returns the turn that started, or null: no deferred
     * turn, another request starting it, the agent switched off, the conversation opened from another workspace
     * than the one it was deferred in, or the budget refusing it — then the turn stays deferred for a later open,
     * with the reason in its `error` (AgentChat::live() reports it as `deferred`, so a surface can show it under
     * the question).
     */
    public function startDeferred(string $conversationId, object $participant): ?AgentTurn
    {
        $started = Cache::lock('agent-turns:deferred:'.$conversationId, 10)->get(function () use ($conversationId, $participant): ?AgentTurn {
            $turn = AgentTurn::query()->forConversation($conversationId)->where('status', AgentTurn::DEFERRED)
                ->where('participant_type', Conversation::participantType($participant))->where('participant_id', Conversation::participantKey($participant))
                ->orderBy('id')->first();

            if (! $turn) {
                return null;
            }

            $runtime = AgentRuntime::capture();
            $tenant = $runtime['tenant'] !== null ? (string) $runtime['tenant'] : null;

            // Conversations are not scoped to a workspace: a turn deferred in one runs only when opened there, so
            // its context and tools are read where the question was asked.
            if ($turn->tenant !== null && $turn->tenant !== $tenant) {
                $turn->forceFill(['error' => __('This question was asked in another workspace. Open the conversation there to get its answer.'), 'updated_at' => now()])->save();

                return null;
            }

            if (($refusal = AgentModels::enabled() ? AgentBudget::refusal($turn->prompt()) : __(':name is switched off.', ['name' => Agents::name()])) !== null) {
                $turn->forceFill(['error' => $refusal, 'updated_at' => now()])->save();

                return null;
            }

            $turn->forceFill([
                'status' => AgentTurn::QUEUED,
                'error' => null,
                'panel' => $runtime['panel'],
                'guard' => $runtime['guard'],
                'tenant' => $runtime['tenant'] !== null ? (string) $runtime['tenant'] : null,
                'locale' => $runtime['locale'],
                'updated_at' => now(),
            ])->save();

            return $turn;
        });

        if (! $started instanceof AgentTurn) {
            return null;
        }

        $this->startNext($conversationId);

        return $started->refresh();
    }

    /** The turn waiting for the person to open the conversation, if any (startDeferred() starts it). */
    public function deferred(string $conversationId): ?AgentTurn
    {
        return AgentTurn::query()->forConversation($conversationId)->where('status', AgentTurn::DEFERRED)->orderBy('id')->first();
    }

    /**
     * Hand the oldest queued turn of the conversation to the queue, unless one is
     * already pending or running there. The question is recorded now.
     */
    public function startNext(string $conversationId): ?AgentTurn
    {
        $this->reconcile($conversationId);

        $turn = DB::connection(config('ai.conversations.connection'))->transaction(function () use ($conversationId): ?AgentTurn {
            if (AgentTurn::query()->forConversation($conversationId)->active()->exists()) {
                return null;
            }

            $turn = AgentTurn::query()->forConversation($conversationId)->where('status', AgentTurn::QUEUED)->orderBy('id')->first();
            if (! $turn) {
                return null;
            }

            // Another request may have taken it in the meantime: only the one that flips it starts it.
            $claimed = AgentTurn::query()->whereKey($turn->id)->where('status', AgentTurn::QUEUED)->update(['status' => AgentTurn::PENDING, 'updated_at' => now()]);

            return $claimed === 1 ? $turn->refresh() : null;
        });

        if (! $turn) {
            return null;
        }

        $participant = $this->participant($turn);
        $store = app(AgentConversationStore::class);

        if ($turn->prompt() !== null && $turn->message_id === null) {
            $messageId = null;

            if ($participant) {
                // Over a proposal still waiting for a decision, the reply may decide it (TypedDecisions: the app's rule, the
                // word lists, the optional classifier): "Yes, go ahead." approves every pending proposal, "No" rejects them,
                // the reply recorded like any question and the turn run as that decision. Anything else is a question of
                // its own: the proposals are declined first, with a note the model reads.
                $pending = $store->pendingCalls($conversationId, $participant);
                $decided = $pending !== [] ? app(TypedDecisions::class)->decide($turn->prompt(), $pending, $turn->model) : null;

                if ($pending !== [] && $decided === null) {
                    $store->declinePending($conversationId, $participant, self::supersededResult());
                }

                $messageId = $store->storeQuestion(
                    $conversationId,
                    $participant,
                    Agents::agentClass(),
                    $turn->prompt(),
                    (array) ($turn->input['attachments'] ?? []),
                    array_filter([
                        'continuation' => ($turn->input['continuation'] ?? false) ? true : null,
                        'mentions' => ($turn->input['mentions'] ?? []) !== [] ? array_values((array) $turn->input['mentions']) : null,
                        'context' => filled($turn->context) ? $turn->context : null, // the record the chat is about stays with the conversation
                    ], fn ($v) => $v !== null),
                );

                if ($decided !== null) {
                    $turn->forceFill(['input' => array_filter([
                        'decisions' => $decided['decisions'],
                        'said' => $turn->prompt(),
                        'decided_by' => $decided['by'],
                        'decision_reason' => $decided['reason'],
                    ], fn ($v) => $v !== null)]);
                }
            }

            $turn->forceFill(['message_id' => $messageId])->save();
        }

        // A decision that does not cover every proposal waiting in that answer is held (queued again) until the
        // others are decided: laravel/ai applies the decisions of one pause together.
        if ($turn->decisions() !== null && $participant) {
            $missing = array_diff_key($store->pendingCalls($conversationId, $participant), $turn->decisions());

            if ($missing !== []) {
                $turn->forceFill(['status' => AgentTurn::QUEUED])->save();

                return null;
            }
        }

        $job = new RunAgentTurn($turn->id, [
            'panel' => $turn->panel,
            'guard' => $turn->guard,
            'tenant' => $turn->tenant,
            'user' => $turn->participant_id,
            'locale' => $turn->locale,
        ]);

        $this->dispatch($job, (bool) ($turn->input['sync'] ?? false));

        return $turn->refresh();
    }

    /**
     * Hand the job over as chat.driver says: to a worker, or run it here inside the request. A turn whose input
     * carries `sync` (AgentRun, the email channel, a command) runs here whatever the driver.
     */
    protected function dispatch(RunAgentTurn $job, bool $sync = false): void
    {
        $driver = $sync ? 'sync' : config('packstub-agents.chat.driver', 'queue');

        match ($driver) {
            'queue' => dispatch($job)
                ->onConnection(config('packstub-agents.chat.queue_connection'))
                ->onQueue(config('packstub-agents.chat.queue')),
            'sync' => dispatch_sync($job),
            default => throw new InvalidArgumentException("Unknown agent turn driver [{$driver}]: use 'queue' or 'sync'."),
        };
    }

    /** The job takes the turn: pending → running. False when it was removed or already ran. */
    public function claim(AgentTurn $turn): bool
    {
        $claimed = AgentTurn::query()->whereKey($turn->id)->where('status', AgentTurn::PENDING)->update([
            'status' => AgentTurn::RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 1) {
            $turn->refresh();
        }

        return $claimed === 1;
    }

    /**
     * What the answer looks like so far, for the page: the text, the status line, and the tools called so far
     * (names, in call order) so a surface can list them while the turn runs.
     *
     * @param  list<string>|null  $tools
     */
    public function snapshot(AgentTurn $turn, ?string $text, ?string $statusText, ?array $tools = null): void
    {
        $turn->forceFill(['text' => $text, 'status_text' => $statusText, 'updated_at' => now()] + ($tools === null ? [] : ['tool_calls' => $tools]))->save();
    }

    public function requestStop(AgentTurn $turn): void
    {
        AgentTurn::query()->whereKey($turn->id)->active()->whereNull('stop_requested_at')->update(['stop_requested_at' => now()]);
    }

    /** A fresh read of the flag, for the job between events. */
    public function stopRequested(AgentTurn $turn): bool
    {
        return AgentTurn::query()->whereKey($turn->id)->whereNotNull('stop_requested_at')->exists();
    }

    /**
     * The turn ended: how, and what it cost. $metrics is what the job measured (provider, model_name, usage,
     * tool_calls, duration_ms, finish_reason); what is missing is derived, so a turn that ended before anything
     * ran (a refusal, a lost worker) still has a duration and a reason. Then one line goes to the log channel.
     *
     * @param  array{provider?: ?string, model_name?: ?string, usage?: ?array, tool_calls?: ?list<string>, duration_ms?: ?int, finish_reason?: ?string}  $metrics
     */
    public function finish(AgentTurn $turn, string $status, ?string $error = null, ?string $text = null, array $metrics = []): void
    {
        $metrics += [
            'duration_ms' => $turn->started_at ? max(0, (int) $turn->started_at->diffInMilliseconds(now())) : null,
            'finish_reason' => match ($status) {
                AgentTurn::STOPPED => 'stopped',
                AgentTurn::FAILED => 'failed',
                default => null,
            },
        ];

        $metrics['cost'] = AgentPricing::cost($metrics['model_name'] ?? $turn->model_name, $metrics['usage'] ?? $turn->usage);

        $turn->forceFill($metrics + [
            'status' => $status,
            'error' => $error,
            'text' => $text ?? $turn->text,
            'status_text' => null,
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->log($turn);

        TurnEnded::dispatch($turn);
    }

    /** The turn's record as one log line, on packstub-agents.log.channel (nothing when it is null). */
    protected function log(AgentTurn $turn): void
    {
        $channel = config('packstub-agents.log.channel');

        if (! $channel) {
            return;
        }

        $usage = $turn->usage ?? [];
        $tools = $turn->tool_calls ?? [];

        Log::channel($channel)->info(sprintf(
            'Agent turn %s: %s, %s tokens in, %s out, %d tool call%s, %.1f s, ended %s',
            $turn->status,
            $turn->provider ? $turn->provider.'/'.$turn->model_name : 'no provider',
            number_format((int) $turn->tokensIn()),
            number_format((int) $turn->tokensOut()),
            count($tools),
            count($tools) === 1 ? '' : 's',
            ($turn->duration_ms ?? 0) / 1000,
            $turn->finish_reason ?? 'unknown',
        ), [
            'turn' => $turn->id,
            'conversation' => $turn->conversation_id,
            'user' => $turn->participant_id,
            'tenant' => $turn->tenant,
            'panel' => $turn->panel,
            'status' => $turn->status,
            'provider' => $turn->provider,
            'model' => $turn->model_name,
            'model_key' => $turn->model,
            'input_tokens' => $turn->tokensIn(),
            'output_tokens' => $turn->tokensOut(),
            'cache_read_input_tokens' => $usage['cache_read_input_tokens'] ?? null,
            'cache_write_input_tokens' => $usage['cache_write_input_tokens'] ?? null,
            'reasoning_tokens' => $usage['reasoning_tokens'] ?? null,
            'tool_calls' => $tools,
            'duration_ms' => $turn->duration_ms,
            'cost' => $turn->cost,
            'finish_reason' => $turn->finish_reason,
            'error' => $turn->error,
        ]);
    }

    /** Whether the conversation is the person's (what the poll and stream endpoints check before answering). */
    public function owned(string $conversationId, ?object $participant): bool
    {
        return $participant !== null && Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', Conversation::participantType($participant))
            ->where('participant_id', Conversation::participantKey($participant))
            ->exists();
    }

    /**
     * What a chat surface reads while an answer is produced — the poll endpoint's payload, and each event of the
     * stream: the running turn (its status line, the answer so far rendered, the tools called so far) and a version
     * stamp that changes whenever the conversation did (another tab, the job finishing, a follow-up queued).
     *
     * @return array{active: ?array{id: string, status: string, statusText: string, html: string, tools: list<string>}, version: string}
     */
    public function state(string $conversationId): array
    {
        $this->reconcile($conversationId);
        $active = $this->active($conversationId);
        $latest = $this->latest($conversationId);
        $updated = Conversation::query()->whereKey($conversationId)->value('updated_at');

        return [
            'active' => $active ? [
                'id' => $active->id,
                'status' => $active->status,
                'statusText' => $this->statusText($active),
                'html' => filled($active->text) ? Markdown::render((string) $active->text) : '',
                'tools' => array_map(fn (string $name) => Str::headline($name), $active->tool_calls ?? []),
            ] : null,
            'version' => md5(json_encode([(string) $updated, $latest?->id, $latest?->status, $this->queued($conversationId)->pluck('id')->all()])),
        ];
    }

    /** The turn the page attaches to: pending or running, oldest first. */
    public function active(string $conversationId): ?AgentTurn
    {
        return AgentTurn::query()->forConversation($conversationId)->active()->orderBy('id')->first();
    }

    /** @return Collection<int, AgentTurn> */
    public function queued(string $conversationId): Collection
    {
        return AgentTurn::query()->forConversation($conversationId)->where('status', AgentTurn::QUEUED)->orderBy('id')->get();
    }

    /** The newest turn of the conversation, whatever its state. */
    public function latest(string $conversationId): ?AgentTurn
    {
        return AgentTurn::query()->forConversation($conversationId)->orderByDesc('id')->first();
    }

    /** Take a queued turn out of the line (a turn that already started is not touched). */
    public function remove(AgentTurn $turn): bool
    {
        return AgentTurn::query()->whereKey($turn->id)->where('status', AgentTurn::QUEUED)->delete() === 1;
    }

    /**
     * A pending or running turn whose job stopped writing for longer than the job timeout (a worker that died,
     * a queue that was never processed) is over: it becomes failed, so the question gets its Retry.
     */
    public function reconcile(string $conversationId): void
    {
        AgentTurn::query()
            ->forConversation($conversationId)
            ->active()
            ->where('updated_at', '<', now()->subSeconds(self::jobTimeout() + 60))
            ->update([
                'status' => AgentTurn::FAILED,
                'error' => __('The answer was interrupted — the worker stopped before it was done.'),
                'status_text' => null,
                'finished_at' => now(),
            ]);
    }

    /**
     * Why an answer that the provider ended is incomplete, or null when it ended properly. A stream that closes
     * without its end event was dropped (an overloaded provider mid-answer); `length` is the model's output limit,
     * `content_filter` the provider's filter, `error`/`unknown` the provider giving up. laravel/ai stores what
     * arrived as the answer either way, so the page marks it and offers Regenerate.
     */
    public static function cutShortReason(?StreamEnd $end): ?string
    {
        if ($end === null) {
            return 'dropped';
        }

        return in_array($end->reason, [
            FinishReason::Length->value,
            FinishReason::ContentFilter->value,
            FinishReason::Error->value,
            FinishReason::Unknown->value,
        ], true) ? $end->reason : null;
    }

    /**
     * What the model reads in place of the tool's result when the person rejects
     * a proposal. A bare rejection would end the turn silently; with a reason
     * laravel/ai carries on, so the model can acknowledge and offer the next step.
     */
    public static function rejectionResult(): string
    {
        return 'The person rejected this change, so it did not run. Do not retry it or propose it again unless asked; acknowledge in one sentence and, if useful, ask what they would like instead.';
    }

    /**
     * A short reply that decides the pending proposals in words: true for "Yes, go ahead." (and its kin in the
     * languages the UI ships), false for "No" / "Cancel", null when the reply is a question of its own. Only a
     * reply of a few words counts; a leading yes or no decides one that goes on ("No, show me the order first").
     */
    public static function decisionInText(string $text): ?bool
    {
        return TypedDecisions::fromWords($text);
    }

    /** What the model reads in place of a proposal's result when the person asked something else instead of deciding on it. */
    public static function supersededResult(): string
    {
        return 'Not decided: the person asked something else instead of approving or rejecting this, so it did not run. Answer the new question; propose it again only if they ask for it.';
    }

    public static function jobTimeout(): int
    {
        return max(30, (int) config('packstub-agents.chat.job_timeout', 600));
    }

    public static function pollInterval(): int
    {
        return max(200, (int) config('packstub-agents.chat.poll_interval', 600));
    }

    public static function workerWait(): int
    {
        return max(1, (int) config('packstub-agents.chat.worker_wait', 10));
    }

    /**
     * A turn handed to the queue that no worker has taken for chat.worker_wait seconds: the usual reason an answer
     * never starts on a fresh install, so the status line names it. The sync driver runs the job inside the request
     * and never waits.
     */
    public function awaitingWorker(AgentTurn $turn): bool
    {
        return $turn->status === AgentTurn::PENDING
            && config('packstub-agents.chat.driver', 'queue') === 'queue'
            && $turn->updated_at !== null
            && $turn->updated_at->lte(now()->subSeconds(self::workerWait()));
    }

    /** The status line for an active turn: what the job last reported, "Thinking…" before it did, the worker hint when none took it. */
    public function statusText(AgentTurn $turn): string
    {
        if ($this->awaitingWorker($turn)) {
            return __('No queue worker has taken this turn yet. Run php artisan queue:work, or set AGENT_TURN_DRIVER=sync to answer inside the request.');
        }

        return $turn->status_text ?? __('Thinking…');
    }

    /** The person the turn belongs to, from the panel's guard. */
    /** The person who asked: the signed-in one when it is them, otherwise retrieved through the guard the turn was asked on. */
    public function participant(AgentTurn $turn): ?object
    {
        $guard = filled($turn->guard) ? Auth::guard($turn->guard) : auth();
        $current = $guard->user() ?? auth()->user();
        if ($current && $current->getMorphClass() === $turn->participant_type && (string) $current->getAuthIdentifier() === (string) $turn->participant_id) {
            return $current;
        }

        return $turn->participant_id !== null ? $guard->getProvider()?->retrieveById($turn->participant_id) : null;
    }
}
