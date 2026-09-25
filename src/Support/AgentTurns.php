<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Models\Conversation;
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
 * as failed, with a Retry.
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
                // Over a proposal still waiting for a decision, the words decide: "Yes, go ahead." approves every pending
                // proposal and "No" rejects them, the reply recorded like any question and the turn run as that decision.
                // Anything else is a question of its own: the proposals are declined first, with a note the model reads.
                $pending = $store->pendingCalls($conversationId, $participant);
                $decision = $pending !== [] ? self::decisionInText($turn->prompt()) : null;

                if ($pending !== [] && $decision === null) {
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
                    ], fn ($v) => $v !== null),
                );

                if ($decision !== null) {
                    $turn->forceFill(['input' => ['decisions' => array_fill_keys(array_keys($pending), $decision), 'said' => $turn->prompt()]]);
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
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
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
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', Str::lower($text))));

        if ($t === '' || count(explode(' ', $t)) > 6) {
            return null;
        }

        $yes = [
            'yes', 'yes please', 'yes go ahead', 'go ahead', 'ok', 'okay', 'sure', 'yep', 'yeah', 'approve', 'approved', 'approve it', 'confirm', 'confirmed', 'confirm it',
            'do it', 'please do', 'proceed', 'go for it', 'sounds good', 'yes do it', 'yes confirm', 'yes confirm it', 'yes approve', 'yes please go ahead',
            'ja', 'ja bitte', 'mach das', 'bestätigen', 'bestätige', 'genehmigen', 'genehmigt', 'weiter', 'los', 'in ordnung',
            'sí', 'si', 'sí por favor', 'si por favor', 'adelante', 'confirmar', 'confírmalo', 'confirmalo', 'aprobar', 'vale', 'hazlo', 'de acuerdo',
            'da', 'da te rog', 'confirmă', 'confirma', 'aprobă', 'aproba', 'mergi mai departe', 'fă o', 'fa o', 'de acord',
            'да', 'давай', 'подтверди', 'подтверждаю', 'одобряю', 'ок', 'хорошо', 'да давай',
        ];
        $no = [
            'no', 'nope', 'no thanks', 'no thank you', 'reject', 'rejected', 'reject it', 'cancel', 'stop', 'never mind', 'do not', 'dont', 'don t', 'leave it', 'not now', 'no do not',
            'nein', 'nein danke', 'abbrechen', 'ablehnen', 'nicht', 'lass es', 'lieber nicht',
            'no gracias', 'cancelar', 'rechazar', 'no lo hagas', 'mejor no',
            'nu', 'nu mulțumesc', 'nu multumesc', 'anulează', 'anuleaza', 'respinge', 'nu acum', 'mai bine nu',
            'нет', 'отмена', 'отклонить', 'не надо', 'не нужно', 'нет спасибо',
        ];

        if (in_array($t, $no, true)) {
            return false;
        }
        if (in_array($t, $yes, true)) {
            return true;
        }

        $first = explode(' ', $t)[0];
        if (in_array($first, ['no', 'nope', 'nein', 'nu', 'нет', 'cancel', 'reject', 'stop', 'never'], true)) {
            return false;
        }
        if (in_array($first, ['yes', 'yep', 'yeah', 'sure', 'ja', 'si', 'sí', 'da', 'да', 'ok', 'okay', 'approve', 'confirm', 'proceed'], true)) {
            return true;
        }

        return null;
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
