<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Packstub\Agents\Ai\Side\ClassifierAgent;
use Packstub\Agents\Ai\Side\SummaryAgent;
use Packstub\Agents\Ai\Side\TitleAgent;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentAnswerVersion;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentPinnedConversation;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Models\ConversationClassification;
use Packstub\Agents\Models\ConversationSummary;
use Throwable;

/**
 * laravel/ai records a question and its answer together, once the answer has
 * arrived — so a question the provider never answered was lost. This store
 * lets the chat record the question first: storeQuestion() writes the row
 * before the provider is called, answering() tells the store which row the
 * turn belongs to, and the SDK then attaches the answer to it (the question
 * is neither stored twice nor fed back to the model as history).
 *
 * It also decides what a long chat replays (config `history`): the most
 * recent turns that fit a token budget, older tool results reduced to a
 * placeholder, and — when a summarizer is set for the turn — the messages
 * that fall out of the window folded into a rolling summary the model reads
 * first. Without a summarizer they are simply left out, as laravel/ai does.
 */
class AgentConversationStore extends DatabaseConversationStore
{
    /** The pre-stored question the running turn answers, if any. */
    protected ?string $answering = null;

    /** fn (string $prompt): string — writes the rolling summary with a cheap model; null: no compaction this turn. */
    protected ?Closure $summarizer = null;

    /** The provider whose name a cache breakpoint in the history is tagged with; null: no breakpoint (only Anthropic reads one). */
    protected ?string $cacheBreakpointsFor = null;

    /** At most this many rows are read per load (bounds the first compaction of a very long chat). */
    public const SUMMARY_ROWS_CAP = 400;

    /**
     * Open a conversation for the person, titled after the first question until the answer arrives — or with
     * $title as given, for a conversation the app opens itself (a digest it posts into, a prompt it defers).
     */
    public function startConversation(object $participant, string $prompt, ?string $title = null): string
    {
        return $this->storeConversation(
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $title ?? Str::limit($prompt, 50, preserveWords: true),
        );
    }

    /**
     * Record a question before it is sent to the provider and return the message ID. $attachments are the stored
     * files (laravel/ai's array form, File::toArray) the provider reads with it; $meta marks a continuation
     * ("continue where you stopped"), which a surface shows as part of the answer above rather than a question.
     *
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $meta
     */
    public function storeQuestion(string $conversationId, object $participant, string $agentClass, string $content, array $attachments = [], array $meta = []): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass,
                'role' => 'user',
                'content' => $content,
                'attachments' => json_encode(array_values($attachments)),
                'steps' => '[]',
                'usage' => '[]',
                'meta' => json_encode($meta === [] ? [] : $meta),
                'status' => MessageStatus::Completed->value,
            ],
        ));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /** Whether a stored question is a continuation of the answer above it (sent by "Continue", not typed). */
    public static function isContinuation(mixed $meta): bool
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return (bool) (is_array($meta) ? ($meta['continuation'] ?? false) : false);
    }

    /**
     * The records a question mentioned ("@Order RO-00012"), as stored in its meta: ref and label.
     *
     * @return list<array{ref: string, label: string}>
     */
    public static function mentionsOf(mixed $meta): array
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $list = is_array($meta) ? ($meta['mentions'] ?? []) : [];

        return is_array($list) ? array_values(array_filter($list, fn ($m) => is_array($m) && isset($m['ref'], $m['label']))) : [];
    }

    /**
     * The stored attachments of a message, as laravel/ai wrote them (a list of File::toArray arrays).
     *
     * @return list<array<string, mixed>>
     */
    public static function attachmentsOf(mixed $attachments): array
    {
        $list = is_string($attachments) ? json_decode($attachments, true) : $attachments;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Rename a conversation (the person's own title; the provider no longer titles it). */
    public function renameConversation(string $conversationId, string $title): void
    {
        $this->table($this->conversationsTable())->where('id', $conversationId)->update(['title' => Str::limit(trim($title), 100, ''), 'updated_at' => now()]);
    }

    /**
     * The proposals still waiting for a decision: every call a paused answer of this person listed as pending
     * and that has no result yet, keyed by call id with its name, arguments and provider call id.
     *
     * @return array<string, array{name: string, arguments: array<string, mixed>, result_id: ?string}>
     */
    public function pendingCalls(string $conversationId, object $participant): array
    {
        $pending = [];

        foreach ($this->pausedRows($conversationId, $participant) as $row) {
            foreach (self::callsOf($row) as $call) {
                if (isset($call['id']) && PendingApproval::isPending($call)) {
                    $pending[$call['id']] = [
                        'name' => (string) ($call['name'] ?? ''),
                        'arguments' => (array) ($call['arguments'] ?? []),
                        'result_id' => $call['result_id'] ?? null,
                    ];
                }
            }
        }

        return $pending;
    }

    /**
     * Decline the proposals still waiting for a decision, because the person asked something else: each pending
     * call gets a denied result carrying $note, the way a rejection is stored. laravel/ai cannot continue a
     * conversation over a pending call, and once the next answer had a proposal of its own, a decision on either
     * could no longer be matched to its turn. Returns how many calls were declined.
     */
    public function declinePending(string $conversationId, object $participant, string $note): int
    {
        $pending = $this->pendingCalls($conversationId, $participant);

        if ($pending === []) {
            return 0;
        }

        $this->storeApprovalResults($conversationId, array_map(
            fn (string $id) => new ToolResult($id, $pending[$id]['name'], $pending[$id]['arguments'], $note, $pending[$id]['result_id'], true),
            array_keys($pending),
        ));

        // Every proposal of those answers is decided now: they are no longer paused, and the raw provider state
        // they kept for a resume can go (the next question starts a turn of its own).
        $this->settlePausedRows($conversationId, array_keys($pending));

        return count($pending);
    }

    /** The person's answers that paused for a decision, newest first. */
    protected function pausedRows(string $conversationId, object $participant): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('participant_type', Conversation::participantType($participant))
            ->where('participant_id', Conversation::participantKey($participant))
            ->where('role', 'assistant')
            ->where('status', MessageStatus::Paused->value)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The paused answers whose every waiting proposal is among $callIds (decided now, or already answered) become
     * completed rows, without the raw provider blocks a resume would have replayed. laravel/ai folds a resume into the row it paused on; the
     * chat keeps a reply the person typed ("Yes, go ahead.") between the proposal and what followed, so the
     * answer to it is stored as a row of its own instead (storeAssistantMessage).
     *
     * @param  list<string>  $callIds
     */
    protected function settlePausedRows(string $conversationId, array $callIds): void
    {
        $rows = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('status', MessageStatus::Paused->value)
            ->get();

        foreach ($rows as $row) {
            $calls = self::callsOf($row);

            $waiting = collect($calls)->filter(fn (array $call) => PendingApproval::isPending($call))->pluck('id');

            if (array_intersect(array_column($calls, 'id'), $callIds) === [] || $waiting->diff($callIds)->isNotEmpty()) {
                continue;
            }

            $this->table($this->messagesTable())->where('id', $row->id)->update([
                'status' => MessageStatus::Completed->value,
                'steps' => $this->withoutReplayBlocks($this->decodedSteps($row))->toJson(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A reply the person typed over a proposal ("Yes, go ahead.") is recorded as a question before the decision
     * runs, so what the assistant says next is stored after it, as an answer of its own, rather than folded into
     * the paused answer above the reply as laravel/ai does for a decision made with the buttons.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        if ($this->answering !== null && $prompt->hasApprovalDecisions()) {
            $this->settlePausedRows($conversationId, array_keys($prompt->approvalDecisions->all()));
        }

        $messageId = parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);

        if ($messageId !== null) {
            $this->redactMessage($messageId, $conversationId);
        }

        return $messageId;
    }

    /** The results an approval produced are written to the paused answer before the run continues: redacted there too. */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        parent::storeApprovalResults($conversationId, $toolResults);

        if ($toolResults === [] || ! AgentRedactor::enabled()) {
            return;
        }

        $ids = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        foreach ($this->table($this->messagesTable())->where('conversation_id', $conversationId)->where('role', 'assistant')->orderByDesc('id')->limit(20)->get(['id', 'steps']) as $row) {
            if (array_intersect(array_column(self::callsOf($row), 'id'), $ids) !== []) {
                $this->redactMessage($row->id, $conversationId);
            }
        }
    }

    /**
     * Take secrets and personal data out of a stored answer (config `redact`): its text, each step's text and
     * reasoning, and every tool result kept with it — what the person reads in the transcript and what the model
     * reads again as history. The raw provider blocks a paused answer keeps for its resume are left as they are
     * (the provider needs them byte for byte) and go when the turn completes.
     */
    protected function redactMessage(string $messageId, string $conversationId): void
    {
        if (! AgentRedactor::enabled()) {
            return;
        }

        $row = $this->table($this->messagesTable())->where('id', $messageId)->first(['id', 'content', 'steps']);

        if (! $row) {
            return;
        }

        $redactor = app(AgentRedactor::class);
        $content = $redactor->redact((string) $row->content);
        $changed = $content !== (string) $row->content;

        // Decoded as objects, so everything that is not touched — the raw blocks above all — is written back as it was.
        $steps = json_decode((string) $row->steps);

        foreach (is_array($steps) ? $steps : [] as $step) {
            if (! is_object($step)) {
                continue;
            }

            foreach (['content', 'reasoning'] as $key) {
                if (is_string($step->{$key} ?? null) && ($clean = $redactor->redact($step->{$key})) !== $step->{$key}) {
                    $step->{$key} = $clean;
                    $changed = true;
                }
            }

            foreach (is_array($step->tool_calls ?? null) ? $step->tool_calls : [] as $call) {
                if (! is_object($call) || ! property_exists($call, 'result') || $call->result === null) {
                    continue;
                }

                $result = is_string($call->result) ? $call->result : json_decode((string) json_encode($call->result), true);
                $clean = $redactor->redactResult($result);

                if ($clean !== $result) {
                    $call->result = $clean;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->table($this->messagesTable())->where('id', $messageId)->update(['content' => $content, 'steps' => is_array($steps) ? json_encode($steps) : $row->steps]);
        }

        $redactor->report(['conversation' => $conversationId, 'message' => $messageId]);
    }

    /**
     * Store what the assistant had written when the person stopped it. laravel/ai stores an answer only once the
     * stream has ended, so a stopped turn stores its own, marked in meta so the page can say so.
     */
    public function storeStoppedAnswer(string $conversationId, object $participant, string $agentClass, string $content): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass,
                'role' => 'assistant',
                'content' => $content,
                'attachments' => '[]',
                'steps' => json_encode([['content' => $content, 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
                'usage' => '[]',
                'meta' => json_encode(['stopped' => true]),
                'status' => MessageStatus::Completed->value,
            ],
        ));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /** Whether a stored answer was cut short by the person. */
    public static function wasStopped(mixed $meta): bool
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return (bool) (is_array($meta) ? ($meta['stopped'] ?? false) : false);
    }

    /**
     * Post a message the app wrote as the assistant — a digest, a reminder, a notice — into the conversation: an
     * ordinary assistant row the next turn reads as history, marked `posted` in meta so a surface can say so and
     * the budget leaves it out (no provider wrote it, no tokens were spent). The turn log lists it as a done
     * turn ended `posted`, without provider, usage or cost. Returns the message id.
     */
    public function storePostedMessage(string $conversationId, object $participant, string $content, ?string $agentClass = null): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();
        $runtime = AgentRuntime::capture();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass ?? Agents::agentClass(),
                'role' => 'assistant',
                'content' => $content,
                'attachments' => '[]',
                'steps' => json_encode([['content' => $content, 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
                'usage' => '[]',
                'meta' => json_encode(['posted' => true]),
                'status' => MessageStatus::Completed->value,
            ],
        ));

        AgentTurn::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => Conversation::participantType($participant),
            'participant_id' => Conversation::participantKey($participant),
            'message_id' => $messageId,
            'status' => AgentTurn::DONE,
            'input' => ['posted' => true],
            'text' => $content,
            'panel' => $runtime['panel'],
            'guard' => $runtime['guard'],
            'tenant' => $runtime['tenant'] !== null ? (string) $runtime['tenant'] : null,
            'locale' => $runtime['locale'],
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'finish_reason' => AgentTurn::POSTED,
        ]);

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /** Whether a stored answer was posted by the app (storePostedMessage), not written by the model. */
    public static function wasPosted(mixed $meta): bool
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return (bool) (is_array($meta) ? ($meta['posted'] ?? false) : false);
    }

    /**
     * Mark the newest answer of the conversation as ended early by the provider (AgentTurns::cutShortReason),
     * on the row laravel/ai stored for it.
     */
    public function markCutShort(string $conversationId, string $reason): void
    {
        $this->markLatestAnswer($conversationId, ['cut_short' => $reason]);
    }

    /** Mark the newest answer as taken by a failover provider (the first choice refused the turn), so the page says so. */
    public function markAnsweredBy(string $conversationId, string $provider, string $model): void
    {
        $this->markLatestAnswer($conversationId, ['answered_by' => ['provider' => $provider, 'model' => $model]]);
    }

    /** Merge $meta into the newest answer's meta (the row laravel/ai stored for it). */
    protected function markLatestAnswer(string $conversationId, array $meta): void
    {
        $row = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first(['id', 'meta']);

        if (! $row) {
            return;
        }

        $existing = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;

        $this->table($this->messagesTable())
            ->where('id', $row->id)
            ->update(['meta' => json_encode([...(is_array($existing) ? $existing : []), ...$meta])]);
    }

    /** Why a stored answer was ended early by the provider, or null. */
    public static function cutShort(mixed $meta): ?string
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $reason = is_array($meta) ? ($meta['cut_short'] ?? null) : null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * The failover provider and model that answered instead of the first choice, or null.
     *
     * @return ?array{provider: string, model: string}
     */
    public static function answeredBy(mixed $meta): ?array
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $by = is_array($meta) ? ($meta['answered_by'] ?? null) : null;

        return is_array($by) && is_string($by['provider'] ?? null) && $by['provider'] !== ''
            ? ['provider' => $by['provider'], 'model' => (string) ($by['model'] ?? '')]
            : null;
    }

    /**
     * Forget everything after a question (its answer, a paused proposal, the feedback on them) so the question
     * can be answered again — Regenerate, and Edit on the last question. The rolling summary is not touched:
     * it only ever covers rows older than the last exchange.
     */
    public function dropMessagesAfter(string $conversationId, string $messageId, bool $keepVersion = true): void
    {
        $rows = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $messageId)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // The answer that goes is kept as a version of the question, so it can be read again or put back.
        if ($keepVersion) {
            $question = $this->table($this->messagesTable())->where('id', $messageId)->value('content');

            AgentAnswerVersion::query()->create([
                'conversation_id' => $conversationId,
                'question_id' => $messageId,
                'question' => (string) $question,
                'rows' => $rows->map(fn ($row) => (array) $row)->all(),
            ]);
        }

        $ids = $rows->pluck('id');
        AgentMessageFeedback::query()->whereIn('message_id', $ids)->delete();
        $this->table($this->messagesTable())->whereIn('id', $ids)->delete();
        $this->touchConversation($conversationId, now());
    }

    /**
     * The earlier answers to a question (AgentAnswerVersion rows), oldest first.
     *
     * @return Collection<int, AgentAnswerVersion>
     */
    public function versionsOf(string $conversationId, string $questionId): Collection
    {
        return AgentAnswerVersion::query()->where('conversation_id', $conversationId)->where('question_id', $questionId)->orderBy('id')->get();
    }

    /**
     * Put an earlier answer back: the rows after the question now become a version of their own, the chosen
     * version's rows are restored as they were (a proposal still waiting then waits again), the question's text
     * comes back with them, and the version row goes. False when the version is not this question's.
     */
    public function restoreVersion(string $conversationId, string $questionId, int $versionId): bool
    {
        $version = AgentAnswerVersion::query()->whereKey($versionId)->where('conversation_id', $conversationId)->where('question_id', $questionId)->first();

        if (! $version) {
            return false;
        }

        $this->dropMessagesAfter($conversationId, $questionId);

        foreach ($version->rows as $row) {
            $this->table($this->messagesTable())->insert(self::rowInCurrentShape($row));
        }

        if ($version->question !== '') {
            $this->table($this->messagesTable())->where('id', $questionId)->update(['content' => $version->question]);
        }

        $version->delete();
        $this->touchConversation($conversationId, now());

        return true;
    }

    /** Replace the text of a recorded question (Edit on the last question). */
    public function rewriteQuestion(string $conversationId, string $messageId, string $content): void
    {
        $this->table($this->messagesTable())->where('conversation_id', $conversationId)->where('id', $messageId)->where('role', 'user')->update(['content' => $content, 'updated_at' => now()]);
        $this->touchConversation($conversationId, now());
    }

    /**
     * Move a recorded question to the end of the conversation and return its new id: a question that never got an
     * answer and was followed by others is sent again as the newest one, so its answer lands under it. The row
     * keeps its text, files and mentions; its earlier answers (versions) and its turns follow the new id.
     */
    public function moveQuestionToEnd(string $conversationId, string $messageId): string
    {
        $newId = (string) Str::uuid7();
        $now = now();

        $moved = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('id', $messageId)
            ->where('role', 'user')
            ->update(['id' => $newId, 'created_at' => $now, 'updated_at' => $now]);

        if ($moved === 0) {
            return $messageId;
        }

        AgentAnswerVersion::query()->where('conversation_id', $conversationId)->where('question_id', $messageId)->update(['question_id' => $newId]);
        AgentTurn::query()->forConversation($conversationId)->where('message_id', $messageId)->update(['message_id' => $newId]);
        $this->touchConversation($conversationId, $now);

        return $newId;
    }

    /**
     * The page context the conversation was last asked with ("orders/12"): the newest question that recorded one,
     * else — for a chat from before questions recorded it — the newest turn's. Null for a chat about no record.
     */
    public function contextOf(string $conversationId): ?string
    {
        $metas = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('meta');

        foreach ($metas as $meta) {
            $meta = is_string($meta) ? json_decode($meta, true) : $meta;

            if (is_array($meta) && is_string($meta['context'] ?? null) && $meta['context'] !== '') {
                return $meta['context'];
            }
        }

        return AgentTurn::query()->forConversation($conversationId)->whereNotNull('context')->orderByDesc('id')->value('context');
    }

    /** The turn about to run answers this pre-stored question (null: none, the SDK stores the question itself). */
    public function answering(?string $messageId): void
    {
        $this->answering = $messageId;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        if ($this->answering === null) {
            return parent::storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
        }

        $messageId = $this->answering;
        $this->answering = null;
        $this->touchConversation($conversationId, now());

        return $messageId;
    }

    /** The summarizer the next history load may use for compaction (null: leave dropped messages out silently). */
    public function summarizeWith(?Closure $summarizer): void
    {
        $this->summarizer = $summarizer;
    }

    /**
     * Mark the stable part of the history for the provider's prompt cache when it reads explicit
     * breakpoints (Anthropic; the others cache every prefix they have seen on their own).
     */
    public function cachingFor(?TextProvider $provider): void
    {
        $this->cacheBreakpointsFor = $provider?->driver() === 'anthropic' ? $provider->name() : null;
    }

    /** A summarizer on the provider's cheapest model: the SummaryAgent side agent, which answers with the summary as a field. */
    public static function providerSummarizer(TextProvider $provider): Closure
    {
        return fn (string $prompt): string => (string) (SummaryAgent::run($prompt, $provider)['summary'] ?? '');
    }

    /**
     * The history the model reads: the summary so far (if any), then the most recent
     * turns that fit the budget with older tool results pruned. What no longer fits is
     * summarized when a summarizer is set. While a pre-stored question is being answered
     * it is the conversation's last row and the SDK sends it as the prompt — so it is left
     * out here rather than shown twice. For a provider with explicit cache breakpoints the
     * newest answer that will not change again carries one.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id); // newest first
        [$kept, $dropped] = $this->fitBudget($records, $limit, $summary);

        if ($dropped->isNotEmpty() && $this->summarizer !== null) {
            $summary = $this->compact($conversationId, $summary, $dropped->reverse()->values()) ?? $summary;
        }

        $messages = $kept->isEmpty() ? collect() : parent::getLatestConversationMessages($conversationId, $kept->count());
        $messages = $this->pruneToolResults($messages);

        if ($this->answering !== null && $this->isUserMessage($messages->last())) {
            $messages->pop();
        }

        // A window that opens on a message the app posted (nothing summarized before it): a provider may require the
        // first message to be the person's, so one line says what follows is the assistant's own.
        if ($summary === null && $messages->isNotEmpty() && ! $this->isUserMessage($messages->first())) {
            $messages->prepend(new Message('user', __('(This conversation starts with a message you posted.)')));
        }

        if ($summary !== null) {
            $messages->prepend(new AssistantMessage(__('Understood, I will build on that summary.')));
            $messages->prepend(new Message('user', __('Summary of the earlier part of this conversation (those messages are not shown again):')."\n\n".$summary->content));
        }

        return $this->cacheBreakpointsFor === null ? $messages : $this->markStablePrefix($messages);
    }

    /**
     * Put the provider's cache breakpoint on the newest answer in front of the last
     * keep_tool_results_turns questions: the tool results before it are already placeholders,
     * so everything up to there is replayed byte for byte on every later turn and the cache
     * hits, while the recent turns behind it — the ones still being pruned — are read fresh.
     * With fewer turns than that the breakpoint lands on the summary's acknowledgement, if any.
     * Only a plain text answer can carry the marker (it is sent as a raw provider block).
     */
    protected function markStablePrefix(Collection $messages): Collection
    {
        $keep = self::keepToolResultsTurns();
        $turns = 0;

        for ($i = $messages->count() - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($this->isUserMessage($message)) {
                $turns++;

                continue;
            }

            if ($turns >= $keep && $message instanceof AssistantMessage && $message->toolCalls->isEmpty() && $message->replayBlocks === [] && filled($message->content)) {
                $messages[$i] = new AssistantMessage($message->content, replayBlocks: [
                    ['type' => 'text', 'text' => $message->content, 'cache_control' => ['type' => 'ephemeral']],
                ], replayBlocksProvider: $this->cacheBreakpointsFor);

                break;
            }
        }

        return $messages;
    }

    /**
     * How full the history window is, for the chat page's meter, and what fills it: the rolling summary, the
     * questions, the answers, the tool calls, and the tool results the model still reads verbatim or reduced
     * to a placeholder. All estimated (four characters per token).
     *
     * @return array{tokens: int, budget: int, share: float, summarized: bool, source: ?string, breakdown: array{summary: int, questions: int, answers: int, tool_calls: int, tool_results: int, tool_results_pruned: int}}
     */
    public function contextUsage(string $conversationId): array
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id);
        $budget = self::budget();
        $breakdown = ['summary' => $summary ? self::estimateTokens($summary->content) : 0, 'questions' => 0, 'answers' => 0, 'tool_calls' => 0, 'tool_results' => 0, 'tool_results_pruned' => 0];

        foreach ($records->values() as $i => $record) {
            $parts = $this->estimateParts($record, $i);
            $breakdown[$record->role === 'user' ? 'questions' : 'answers'] += $parts['content'];
            $breakdown['tool_calls'] += $parts['tool_calls'];
            $breakdown[$parts['pruned'] ? 'tool_results_pruned' : 'tool_results'] += $parts['tool_results'];
        }

        $tokens = array_sum($breakdown);

        return [
            'tokens' => $tokens,
            'budget' => $budget,
            'share' => $budget > 0 ? min(1.0, $tokens / $budget) : 0.0,
            'summarized' => $summary !== null,
            'source' => $summary?->source_conversation_id,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Fold everything but the last $keepTurns exchanges into the rolling summary, in place ("Compress now" on the
     * chat page). Returns false when there is nothing older than those exchanges; a failing summarizer throws,
     * so the page can say so, and the summary so far is kept.
     */
    public function compactNow(string $conversationId, Closure $summarizer, int $keepTurns): bool
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id); // newest first
        $kept = 0;
        $questions = 0;

        // Newest first: an exchange is kept whole, from its answer back to its question.
        foreach ($records as $record) {
            if ($questions >= max(0, $keepTurns)) {
                break;
            }

            $kept++;

            if ($record->role === 'user') {
                $questions++;
            }
        }

        $dropped = $records->slice($kept)->reverse()->values();

        if ($dropped->isEmpty()) {
            return false;
        }

        $this->writeSummary($conversationId, $summary, $dropped, $summarizer);

        return true;
    }

    /**
     * Open a new conversation that carries a summary of this one, and return its ID.
     * The summary covers everything the model would read now: the summary so far plus the rows after it.
     */
    public function continueConversation(string $conversationId, object $participant, string $title, Closure $summarizer): string
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id)->reverse()->values();
        $content = $summarizer($this->summaryPrompt($summary?->content, $records));

        $newId = $this->storeConversation(Conversation::participantType($participant), Conversation::participantKey($participant), $title);

        ConversationSummary::query()->create([
            'conversation_id' => $newId,
            'through_message_id' => null,
            'source_conversation_id' => $conversationId,
            'content' => $content,
        ]);

        return $newId;
    }

    /** The conversation's rows newer than $afterId (all of them when null), newest first, capped. */
    protected function recordsAfter(string $conversationId, ?string $afterId): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderByDesc('id')
            ->limit(self::SUMMARY_ROWS_CAP)
            ->get();
    }

    /**
     * Split newest-first rows into what fits the budget (at most $limit rows, cut on a turn boundary so the
     * oldest kept row is a question) and what drops out.
     *
     * @return array{0: Collection, 1: Collection}
     */
    protected function fitBudget(Collection $records, int $limit, ?ConversationSummary $summary): array
    {
        $budget = self::budget() - ($summary ? self::estimateTokens($summary->content) : 0);
        $used = 0;
        $kept = collect();

        foreach ($records->values() as $i => $record) {
            $used += $this->estimateRecord($record, $i);

            if ($kept->isNotEmpty() && ($used > $budget || $kept->count() >= $limit)) {
                break;
            }

            $kept->push($record);
        }

        // Cut on a turn boundary: the oldest kept row must be a question, or a tool call could lose its result. A
        // message the app posted has no calls, so a window may open on it (a digest, then the person's reply).
        while ($kept->count() > 1 && $kept->last()->role !== 'user' && ! self::wasPosted($kept->last()->meta)) {
            $kept->pop();
        }

        return [$kept, $records->values()->slice($kept->count())->values()];
    }

    /** Fold $dropped (oldest first) into the rolling summary; null when the summarizer failed (the rows are left out this turn). */
    protected function compact(string $conversationId, ?ConversationSummary $summary, Collection $dropped): ?ConversationSummary
    {
        try {
            return $this->writeSummary($conversationId, $summary, $dropped, $this->summarizer);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Extend the summary so far with $dropped (oldest first) and store it; null when the summarizer wrote nothing. */
    protected function writeSummary(string $conversationId, ?ConversationSummary $summary, Collection $dropped, Closure $summarizer): ?ConversationSummary
    {
        $content = $summarizer($this->summaryPrompt($summary?->content, $dropped));

        if (trim($content) === '') {
            return null;
        }

        return ConversationSummary::query()->updateOrCreate(
            ['conversation_id' => $conversationId],
            ['content' => trim($content), 'through_message_id' => $dropped->last()->id],
        );
    }

    /** What the summarizer reads: the summary so far and the rows (oldest first) to fold into it, tool payloads reduced to a line. */
    protected function summaryPrompt(?string $existing, Collection $records): string
    {
        $lines = $records->map(function ($record): string {
            $content = Str::limit(trim((string) $record->content), 1200);
            $calls = collect(self::callsOf($record))
                ->map(fn ($call) => ($call['name'] ?? 'tool').' '.json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE))
                ->implode('; ');

            return ($record->role === 'user' ? 'Person: ' : 'Assistant: ').$content.($calls !== '' ? " [called: {$calls}]" : '');
        })->implode("\n");

        return ($existing !== null ? "Existing summary:\n{$existing}\n\n" : '')."New messages:\n{$lines}";
    }

    /** Tool results before the last keep_tool_results_turns questions are replaced by a placeholder; the calls stay. */
    protected function pruneToolResults(Collection $messages): Collection
    {
        $keep = self::keepToolResultsTurns();
        $turns = 0;

        for ($i = $messages->count() - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($this->isUserMessage($message)) {
                $turns++;

                continue;
            }

            if ($turns >= $keep && $message instanceof ToolResultMessage) {
                $message->toolResults = $message->toolResults->map(fn (ToolResult $result) => new ToolResult(
                    $result->id,
                    $result->name,
                    $result->arguments,
                    self::placeholder($result->name, strlen(is_string($result->result) ? $result->result : json_encode($result->result))),
                    $result->resultId,
                    $result->denied,
                ));
            }
        }

        return $messages;
    }

    protected static function placeholder(string $tool, int $bytes): string
    {
        return "[{$tool} result omitted from history ({$bytes} bytes); call the tool again if you need it]";
    }

    /** A row's share of the window, as the model would read it ($index counts rows from the newest). */
    protected function estimateRecord(object $record, int $index): int
    {
        $parts = $this->estimateParts($record, $index);

        return $parts['content'] + $parts['tool_calls'] + $parts['tool_results'];
    }

    /**
     * A row's share of the window by part: its text, its tool calls, and its tool results — reduced to a
     * placeholder's worth once the row is older than keep_tool_results_turns exchanges.
     *
     * @return array{content: int, tool_calls: int, tool_results: int, pruned: bool}
     */
    protected function estimateParts(object $record, int $index): array
    {
        $calls = self::callsOf($record);
        $results = json_encode(array_values(array_filter(array_column($calls, 'result'), fn ($r) => $r !== null && $r !== '')));
        $any = strlen($results) > 2; // not '[]'
        $pruned = $any && $index >= self::keepToolResultsTurns() * 2;

        return [
            'content' => self::estimateTokens((string) $record->content),
            // The call as the model replays it: its id, name and arguments, without the result and the approval bookkeeping.
            'tool_calls' => $calls === [] ? 0 : self::estimateTokens(json_encode(array_map(fn (array $call) => array_diff_key($call, array_flip(['result', 'denied', 'failed', 'approval_reason'])), $calls))),
            'tool_results' => $pruned ? 40 : ($any ? self::estimateTokens($results) : 0),
            'pruned' => $pruned,
        ];
    }

    /** A rough token count (four characters per token) — enough to decide what fits. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public static function budget(): int
    {
        return max(1000, (int) config('packstub-agents.history.max_tokens', 24000));
    }

    public static function keepToolResultsTurns(): int
    {
        return max(0, (int) config('packstub-agents.history.keep_tool_results_turns', 3));
    }

    /** How many of the latest exchanges "Compress now" keeps verbatim. */
    public static function compressKeepTurns(): int
    {
        return max(1, (int) config('packstub-agents.history.compress_keep_turns', 2));
    }

    /** From this share of the window the chat page shows its context ring. */
    public static function meterShare(): float
    {
        return max(0.0, (float) config('packstub-agents.history.meter_share', 0.25));
    }

    /** Title a conversation the way laravel/ai does once its first answer is in: a short provider-written line, else the question. */
    public function titleConversation(string $conversationId, string $prompt, TextProvider $provider): void
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return;
        }

        try {
            $title = (string) (TitleAgent::run(Str::limit($prompt, 500), $provider)['title'] ?? '');

            if (($title = trim(Str::limit(trim($title, " \t\n\r\"'"), 100))) !== '') {
                $this->table($this->conversationsTable())->where('id', $conversationId)->update(['title' => $title]);
            }
        } catch (Throwable) {
            // The question stays as the title.
        }
    }

    /**
     * Classify the conversation from its latest messages — topic, sentiment, resolved — with the ClassifierAgent
     * side agent on the provider's cheapest model, and keep the result next to it (ConversationClassification),
     * where a list of chats filters and sorts by it. Off unless config `classify.enabled`; a failure is reported
     * and leaves the earlier classification in place.
     */
    public function classifyConversation(string $conversationId, TextProvider $provider): ?ConversationClassification
    {
        if (! (bool) config('packstub-agents.classify.enabled', false)) {
            return null;
        }

        try {
            $records = $this->recordsAfter($conversationId, null)->take(12)->reverse()->values();

            if ($records->isEmpty()) {
                return null;
            }

            $verdict = ClassifierAgent::run(Str::limit($this->summaryPrompt(null, $records), 6000), $provider);
            $topic = Str::limit(Str::lower(trim((string) ($verdict['topic'] ?? ''))), 60, '');
            $topics = ClassifierAgent::topics();

            if ($topic === '' || ! array_key_exists('resolved', $verdict)) {
                return null;
            }

            return ConversationClassification::query()->updateOrCreate(['conversation_id' => $conversationId], [
                'topic' => $topics === [] || in_array($topic, $topics, true) ? $topic : 'other',
                'sentiment' => in_array($verdict['sentiment'] ?? null, ClassifierAgent::SENTIMENTS, true) ? $verdict['sentiment'] : 'neutral',
                'resolved' => (bool) $verdict['resolved'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function isUserMessage(mixed $message): bool
    {
        return $message instanceof Message && $message->role === MessageRole::User;
    }

    /**
     * A stored row's tool calls across its steps, each with the result that answered it (`result`, `denied`,
     * `failed`) and, while a proposal waits, its `approval_reason`. Rows written before laravel/ai 1.0 are read
     * from their `tool_calls` / `tool_results` columns.
     *
     * @return list<array<string, mixed>>
     */
    public static function callsOf(object|array $record): array
    {
        $record = (array) $record;
        $steps = is_string($record['steps'] ?? null) ? json_decode($record['steps'], true) : ($record['steps'] ?? null);

        if (! is_array($steps)) {
            [$steps] = self::stepsFromLegacyRow($record);
        }

        return array_values(array_filter(array_merge(...array_map(fn ($step) => array_values((array) ($step['tool_calls'] ?? [])), array_values($steps))), 'is_array'));
    }

    /**
     * The steps and status of a row written by laravel/ai 0.x (`tool_calls`, `tool_results`, `approval_state`),
     * the way 1.0 stores them: one step carrying every call, each with its result, a proposal still waiting
     * keeping its question as `approval_reason`. The upgrade migration rewrites every row through this.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public static function stepsFromLegacyRow(array $row): array
    {
        $decode = fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
        $calls = array_values(array_filter($decode($row['tool_calls'] ?? []), 'is_array'));
        $results = collect(array_filter($decode($row['tool_results'] ?? []), 'is_array'))->keyBy(fn (array $r) => (string) ($r['id'] ?? ''));
        $pending = (array) ($decode($row['approval_state'] ?? [])['pending'] ?? []);

        if ($calls === [] && ($row['role'] ?? 'assistant') === 'user') {
            return [[], MessageStatus::Completed->value];
        }

        $stored = array_map(function (array $call) use ($results, $pending): array {
            $id = (string) ($call['id'] ?? '');
            $reason = $pending[$id] ?? null;
            $result = $results->get($id);

            return [
                'id' => $id,
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => (array) ($call['arguments'] ?? []),
                'result_id' => $call['result_id'] ?? null,
                ...(array_key_exists($id, $pending) ? ['approval_reason' => is_string($reason) ? $reason : (is_array($reason) ? ($reason['reason'] ?? null) : null)] : []),
                ...($result === null ? [] : ['result' => $result['result'] ?? null, ...(($result['denied'] ?? false) ? ['denied' => true] : []), ...(($result['failed'] ?? false) ? ['failed' => true] : [])]),
            ];
        }, $calls);

        $paused = collect($stored)->contains(fn (array $call) => PendingApproval::isPending($call));

        return [
            [['content' => (string) ($row['content'] ?? ''), 'tool_calls' => $stored, 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]],
            $paused ? MessageStatus::Paused->value : MessageStatus::Completed->value,
        ];
    }

    /**
     * A row kept as an answer version, in the shape the table has now: one saved before laravel/ai 1.0 gets its
     * steps and status, and any column the table no longer has is left out.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rowInCurrentShape(array $row): array
    {
        if (! array_key_exists('steps', $row) || $row['steps'] === null) {
            [$steps, $status] = self::stepsFromLegacyRow($row);
            $row['steps'] = json_encode($steps);
            $row['status'] = $status;
        }

        return array_intersect_key($row, array_flip(Schema::connection($this->connection)->getColumnListing($this->messagesTable())));
    }

    /** Delete a conversation with its messages, their ratings and its rolling summary; its turns stay in the operator's log. */
    public function deleteConversation(string $conversationId): void
    {
        foreach ($this->table($this->messagesTable())->where('conversation_id', $conversationId)->where('role', 'user')->pluck('attachments') as $attachments) {
            AgentAttachments::delete(self::attachmentsOf($attachments));
        }

        AgentMessageFeedback::query()->whereIn('message_id', ConversationMessage::query()->where('conversation_id', $conversationId)->select('id'))->delete();
        ConversationMessage::query()->where('conversation_id', $conversationId)->delete();
        ConversationSummary::query()->where('conversation_id', $conversationId)->delete();
        ConversationClassification::query()->where('conversation_id', $conversationId)->delete();
        AgentAnswerVersion::query()->where('conversation_id', $conversationId)->delete();
        AgentPinnedConversation::query()->where('conversation_id', $conversationId)->delete();
        Conversation::query()->whereKey($conversationId)->delete();
    }
}
