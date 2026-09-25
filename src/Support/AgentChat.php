<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files\File;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Exceptions\ChatBusy;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Models\AgentAnswerVersion;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentPinnedConversation;
use Packstub\Agents\Models\AgentTurn;
use Throwable;

/**
 * One person's chat with the assistant, without a UI: what a chat surface
 * reads (the messages with their proposals, charts and tables; what runs
 * and what waits; the history meter) and what it does (send, decide, retry,
 * regenerate, resend, stop, edit the line, rate an answer, compress, continue
 * in a new chat). A question becomes a turn on the conversation (AgentTurns);
 * the RunAgentTurn job produces the answer while the surface polls the turn
 * row. Filament Agents' chat page is one surface over this class; a JSON API
 * or a widget of your own is another.
 */
class AgentChat
{
    /** @var array{active: ?array, queued: list<array>, held: array<string, bool>, ended: ?array}|null */
    protected ?array $live = null;

    /** Run every turn of this chat inside the call, whatever chat.driver says (AgentRun, the email channel, a command). */
    protected bool $sync = false;

    public function __construct(
        protected Model $participant,
        protected ?string $conversation = null,
        protected string $model = 'auto',
        protected ?string $context = null,
    ) {}

    /**
     * A chat for the person (an Eloquent model: the conversations and ratings are keyed by it), on one of their
     * conversations or a new one. A conversation that is not the person's — another person's, or none — is not
     * found, so a surface can pass the id from the request as is.
     *
     * @throws ModelNotFoundException
     */
    public static function for(Model $participant, ?string $conversation = null, ?string $model = null, ?string $context = null): static
    {
        $chat = new static($participant, $conversation, $model ?? AgentModels::current(), $context);

        if ($conversation !== null && ! $chat->owns($conversation)) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation]);
        }

        return $chat;
    }

    /** The conversation id, null until the first question of a new chat is sent. */
    public function conversation(): ?string
    {
        return $this->conversation;
    }

    public function participant(): Model
    {
        return $this->participant;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    /** Run the turns of this chat inside the call that queues them, whatever chat.driver says. */
    public function sync(bool $sync = true): static
    {
        $this->sync = $sync;

        return $this;
    }

    /** Whether the conversation belongs to the person. */
    public function owns(string $conversationId): bool
    {
        return $this->ownConversations()->whereKey($conversationId)->exists();
    }

    /** The conversation's title (the question, until the provider titles it). */
    public function title(): ?string
    {
        return $this->conversation ? $this->ownConversations()->whereKey($this->conversation)->value('title') : null;
    }

    /** Give the conversation a title of the person's own (nothing without a conversation or with an empty title). */
    public function rename(string $title): bool
    {
        if (! $this->conversation || trim($title) === '') {
            return false;
        }

        app(AgentConversationStore::class)->renameConversation($this->conversation, $title);

        return true;
    }

    /** Pin the conversation to the top of the person's list. */
    public function pin(): bool
    {
        if (! $this->conversation) {
            return false;
        }

        AgentPinnedConversation::query()->firstOrCreate(['conversation_id' => $this->conversation], [
            'participant_type' => $this->participant->getMorphClass(),
            'participant_id' => $this->participant->getKey(),
        ]);

        return true;
    }

    public function unpin(): bool
    {
        return $this->conversation !== null && AgentPinnedConversation::query()->where('conversation_id', $this->conversation)->delete() > 0;
    }

    public function pinned(): bool
    {
        return $this->conversation !== null && AgentPinnedConversation::query()->where('conversation_id', $this->conversation)->exists();
    }

    /**
     * The ids of the person's pinned conversations, most recently pinned first.
     *
     * @return list<string>
     */
    public static function pinnedIds(Model $participant): array
    {
        return AgentPinnedConversation::query()
            ->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey())
            ->orderByDesc('id')
            ->pluck('conversation_id')
            ->all();
    }

    /**
     * The person's conversations whose messages (or title) contain the words, newest first, each with a snippet of
     * the first matching message: what a search box over the chats shows.
     *
     * @return Collection<int, array{conversation: string, title: string, snippet: ?string, at: Carbon}>
     */
    public static function search(Model $participant, string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $own = Conversation::query()
            ->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey());

        $matches = ConversationMessage::query()
            ->whereIn('conversation_id', (clone $own)->select('id'))
            ->where('content', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%')
            ->orderByDesc('id')
            ->limit($limit * 10)
            ->get(['conversation_id', 'content'])
            ->unique('conversation_id');

        $byTitle = (clone $own)->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%')->pluck('id');
        $ids = $matches->pluck('conversation_id')->merge($byTitle)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $snippets = $matches->keyBy('conversation_id');

        return (clone $own)->whereIn('id', $ids)->orderByDesc('updated_at')->limit($limit)->get()->map(fn (Conversation $c) => [
            'conversation' => $c->id,
            'title' => (string) $c->title,
            'snippet' => isset($snippets[$c->id]) ? self::snippet((string) $snippets[$c->id]->content, $query) : null,
            'at' => $c->updated_at,
        ])->values();
    }

    /** A short piece of text around the first occurrence of the words. */
    public static function snippet(string $text, string $query, int $width = 120): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $at = mb_stripos($text, $query);

        if ($at === false || mb_strlen($text) <= $width) {
            return Str::limit($text, $width);
        }

        $start = max(0, $at - (int) ($width / 3));

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($text, $start), $width);
    }

    /** What the chat is about when it was opened from a record (PageContext). */
    public function contextLabel(): ?string
    {
        return PageContext::resolve($this->context)['label'] ?? null;
    }

    /**
     * The starter questions an empty chat offers (Agent::suggestions, with the page context): none once the
     * conversation exists.
     *
     * @return list<string>
     */
    public function suggestions(): array
    {
        if ($this->conversation || ! AgentModels::enabled()) {
            return [];
        }

        return array_values(array_filter(array_map('strval', Agents::agent($this->context, $this->model)->suggestions()), fn (string $s) => trim($s) !== ''));
    }

    /**
     * The model picker's entries by the provider they run on (AgentModels::groups): the label, and under it what the
     * label leaves out — the model's name for an entry with a label of its own ("Fast" → "Claude Haiku 4.5"), the
     * key for one named after its model ("Claude Opus 5" → "Auto").
     *
     * @return array<string, array<string, array{label: string, detail: ?string}>>
     */
    public static function modelMenu(): array
    {
        $catalog = AgentModels::catalog();

        return collect(AgentModels::groups())->map(fn (array $entries) => collect($entries)->map(function (string $label, string $key) use ($catalog) {
            $detail = null;

            if (($catalog[$key]['label'] ?? null) !== null) {
                try {
                    $detail = AgentModels::modelName(AgentModels::modelFor($catalog[$key]['provider'], $key));
                } catch (Throwable) {
                    $detail = null;
                }
            } else {
                $detail = Str::headline($key);
            }

            return ['label' => $label, 'detail' => $detail === null || Str::contains($label, $detail, ignoreCase: true) ? null : $detail];
        })->all())->all();
    }

    /**
     * The conversation's messages, oldest first, each with its proposals (the tool's question, waiting / approved /
     * rejected / held), charts, tables, the person's rating and what the surface may offer on it (Retry on an
     * unanswered question, Edit on the last one, Regenerate on the last answer — while nothing runs).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function messages(): Collection
    {
        if (! $this->conversation) {
            return collect();
        }

        $feedback = AgentMessageFeedback::query()->where('user_id', $this->participant->getKey())->get(['message_id', 'rating', 'note'])->keyBy('message_id');
        $versions = AgentAnswerVersion::query()->where('conversation_id', $this->conversation)->selectRaw('question_id, count(*) as n')->groupBy('question_id')->pluck('n', 'question_id');
        $writeTools = self::writeTools();
        $idle = $this->idle();
        $held = $this->live()['held']; // call id => approved, for decisions waiting for the other proposal of the same answer
        $continuation = false; // the answer after a "Continue" question carries on the one before it

        $list = ConversationMessage::query()
            ->where('conversation_id', $this->conversation)
            ->orderBy('id') // UUIDv7: the order the rows were written, to the millisecond (created_at has seconds)
            ->get()
            ->map(function (ConversationMessage $m) use ($feedback, $versions, $writeTools, $held, &$continuation) {
                $results = collect($m->tool_results ?? [])->keyBy('id');
                $isContinuation = $m->role === 'user' && AgentConversationStore::isContinuation($m->meta);
                $continued = $m->role === 'assistant' && $continuation;
                $continuation = $isContinuation;
                $everPaused = collect($m->approval_state['pending'] ?? [])->keys();
                $pending = $everPaused->reject(fn ($id) => $results->has($id));
                $charts = $results->map(fn ($r) => self::chartFromResult($r['result'] ?? null))->filter()->values()->all();
                $tables = $results->map(fn ($r) => self::tableFromResult($r['result'] ?? null))->filter()->values()->all();

                return [
                    'id' => $m->id,
                    'role' => $m->role,
                    'text' => (string) $m->content,
                    'html' => $m->role === 'assistant' ? Markdown::render((string) $m->content) : e((string) $m->content),
                    // A write tool stays a proposal row (waiting / approved / rejected) after the decision, when the paused list is empty again.
                    'tools' => collect($m->tool_calls ?? [])->map(fn ($call) => [
                        'id' => $call['id'] ?? null,
                        'name' => Str::headline((string) ($call['name'] ?? '')),
                        'tool' => (string) ($call['name'] ?? ''),
                        'question' => self::question($writeTools->get($call['name'] ?? ''), $call['name'] ?? '', $call['arguments'] ?? []),
                        'arguments' => $call['arguments'] ?? [],
                        'pending' => $pending->contains($call['id'] ?? null),
                        'held' => $held[$call['id'] ?? ''] ?? null,
                        'result' => $results->get($call['id'] ?? null)['result'] ?? null,
                        'rejected' => (bool) ($results->get($call['id'] ?? null)['denied'] ?? false),
                        'readOnly' => ! $writeTools->has($call['name'] ?? '') && ! $everPaused->contains($call['id'] ?? null),
                    ])->values()->all(),
                    'charts' => $charts,
                    'tables' => $tables,
                    'rating' => $feedback->get($m->id)?->rating,
                    'ratingNote' => $feedback->get($m->id)?->note,
                    'attachments' => array_map([AgentAttachments::class, 'describe'], AgentConversationStore::attachmentsOf($m->attachments)),
                    'continuation' => $isContinuation, // a question sent by "Continue": a surface hides it
                    'continued' => $continued, // an answer that carries on the previous one
                    'versions' => $m->role === 'user' ? (int) ($versions[$m->id] ?? 0) : 0, // earlier answers to this question
                    'at' => $m->created_at,
                    'stopped' => AgentConversationStore::wasStopped($m->meta),
                    'cutShort' => AgentConversationStore::cutShort($m->meta),
                    'answeredBy' => AgentConversationStore::answeredBy($m->meta),
                    'unanswered' => false,
                    'editable' => false,
                    'regenerable' => false,
                    'continuable' => false,
                ];
            });

        if ($list->isEmpty()) {
            return $list;
        }

        // The last exchange: the last question can be edited and sent again, its answer produced again — while nothing runs.
        $lastQuestion = null;
        for ($i = $list->count() - 1; $i >= 0; $i--) {
            if ($list[$i]['role'] === 'user') {
                $lastQuestion = $i;
                break;
            }
        }

        if ($idle && $lastQuestion !== null) {
            $list->put($lastQuestion, [...$list[$lastQuestion], 'editable' => true]);
        }

        $last = $list->last();

        if ($last['role'] === 'user') {
            // A question with nothing after it was recorded but not answered: while a turn runs it is being answered,
            // otherwise the provider failed or the person stopped it and it gets a Retry.
            $list->push([...$list->pop(), 'unanswered' => $idle]);
        } elseif ($idle && ! collect($last['tools'])->contains('pending', true)) {
            // The last answer: produce it again, or — when the model's length limit cut it — carry on where it stopped.
            $list->push([...$list->pop(), 'regenerable' => true, 'continuable' => $last['cutShort'] === 'length']);
        }

        return $list;
    }

    /**
     * The earlier answers to a question (kept when it was answered again or edited), oldest first: the version id,
     * the question's text at the time, the answer's text and HTML, when it was given.
     *
     * @return Collection<int, array{id: int, question: string, text: string, html: string, at: Carbon|null}>
     */
    public function versions(string $questionId): Collection
    {
        if (! $this->conversation) {
            return collect();
        }

        return app(AgentConversationStore::class)->versionsOf($this->conversation, $questionId)->map(function (AgentAnswerVersion $v) {
            $answer = collect($v->rows)->first(fn (array $row) => ($row['role'] ?? null) === 'assistant' && trim((string) ($row['content'] ?? '')) !== '');
            $text = (string) ($answer['content'] ?? '');

            return ['id' => (int) $v->id, 'question' => (string) $v->question, 'text' => $text, 'html' => Markdown::render($text), 'at' => $v->created_at];
        })->values();
    }

    /**
     * Put an earlier answer to the last question back (the current one becomes a version in turn), so the chat
     * carries on from it. Refused while the chat is busy, for any question but the last, or for a version that is
     * not the question's.
     */
    public function showVersion(string $questionId, int $versionId): bool
    {
        $last = $this->lastQuestion();

        if (! $last || $last->id !== $questionId || ! $this->idle()) {
            return false;
        }

        $this->live = null;

        return app(AgentConversationStore::class)->restoreVersion($this->conversation, $questionId, $versionId);
    }

    /**
     * The conversation as Markdown, for an export: the title, then every question and answer with its time,
     * proposals as one line each ("Approved: Rename widget #3 to Alpha II?").
     */
    public function transcript(): string
    {
        $lines = ['# '.($this->title() ?? __('Chat')), ''];

        foreach ($this->messages() as $message) {
            if ($message['continuation']) {
                continue;
            }

            $who = $message['role'] === 'user' ? __('You') : Agents::name();
            $lines[] = '**'.$who.'** · '.$message['at']?->format('Y-m-d H:i');
            $lines[] = '';

            foreach ($message['tools'] as $tool) {
                if (! $tool['readOnly']) {
                    $lines[] = '> '.($tool['pending'] ? __('Waiting') : ($tool['rejected'] ? __('Rejected') : __('Approved'))).': '.$tool['question'];
                    $lines[] = '';
                }
            }

            foreach ($message['attachments'] as $attachment) {
                $lines[] = '> '.__('Attachment').': '.($attachment['name'] ?? $attachment['type']);
                $lines[] = '';
            }

            if (trim($message['text']) !== '') {
                $lines[] = trim($message['text']);
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The turn that runs on this conversation, the questions waiting behind it, the decisions (call id => approved)
     * held until the other proposals of the same answer are decided, and how the last turn ended when the last
     * question has no answer.
     *
     * @return array{active: ?array{id: string, status: string, statusText: string, html: string}, queued: list<array{id: string, text: string}>, held: array<string, bool>, ended: ?array{status: string, reason: ?string, error: ?string, decision: bool}}
     */
    public function live(): array
    {
        if ($this->live !== null) {
            return $this->live;
        }

        if (! $this->conversation) {
            return $this->live = ['active' => null, 'queued' => [], 'held' => [], 'ended' => null];
        }

        $turns = app(AgentTurns::class);
        $turns->reconcile($this->conversation);
        $active = $turns->active($this->conversation);
        $latest = $turns->latest($this->conversation);
        $queued = $turns->queued($this->conversation);

        return $this->live = [
            'active' => $active ? [
                'id' => $active->id,
                'status' => $active->status,
                'statusText' => $turns->statusText($active), // what the job reports, or the missing-worker hint
                'html' => filled($active->text) ? Markdown::render((string) $active->text) : '',
                'tools' => array_map(fn (string $name) => Str::headline($name), $active->tool_calls ?? []), // called so far, in order
            ] : null,
            'queued' => $queued->filter(fn (AgentTurn $t) => $t->prompt() !== null)->map(fn (AgentTurn $t) => ['id' => $t->id, 'text' => (string) $t->prompt()])->values()->all(),
            'held' => $queued->first(fn (AgentTurn $t) => $t->decisions() !== null)?->decisions() ?? [],
            'ended' => $latest && in_array($latest->status, [AgentTurn::FAILED, AgentTurn::STOPPED], true) ? ['status' => $latest->status, 'reason' => $latest->finish_reason, 'error' => $latest->error, 'decision' => $latest->decisions() !== null] : null,
        ];
    }

    /**
     * Nothing runs or waits on this conversation: no turn in progress, no question in the line, no decision held
     * for the other proposals of its answer. Only another decision may be made while one is held (decide()).
     */
    public function idle(): bool
    {
        $live = $this->live();

        return $live['active'] === null && $live['queued'] === [] && $live['held'] === [];
    }

    /** Forget the live state, so the next read hits the database (after a turn was queued, removed or edited). */
    public function refresh(): static
    {
        $this->live = null;

        return $this;
    }

    /**
     * How full the history window is, for a context meter: the estimate and what fills it
     * (AgentConversationStore::contextUsage), and what the chat cost so far from its ended turns — the last
     * turn's input tokens being the context the provider actually read. `meter` says whether a meter shows
     * (history.meter_share), `notice` whether the chat is long enough to suggest a new one (history.notice_share).
     *
     * @return array{tokens: int, budget: int, share: float, summarized: bool, source: ?string, sourceTitle: ?string, breakdown: array<string, int>, turns: array{count: int, tokens_in: int, tokens_out: int, tool_calls: int, duration_ms: int, last_tokens_in: ?int}, meter: bool, notice: bool}|null
     */
    public function history(): ?array
    {
        if (! $this->conversation) {
            return null;
        }

        $usage = app(AgentConversationStore::class)->contextUsage($this->conversation);

        return [
            ...$usage,
            'sourceTitle' => $usage['source'] ? $this->ownConversations()->whereKey($usage['source'])->value('title') : null,
            'turns' => $this->turnTotals(),
            'meter' => $usage['tokens'] > 0 && $usage['share'] >= AgentConversationStore::meterShare(),
            'notice' => $usage['share'] >= (float) config('packstub-agents.history.notice_share', 0.7),
        ];
    }

    /**
     * What the chat cost so far, over its ended turns.
     *
     * @return array{count: int, tokens_in: int, tokens_out: int, tool_calls: int, duration_ms: int, last_tokens_in: ?int}
     */
    public function turnTotals(): array
    {
        if (! $this->conversation) {
            return ['count' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'tool_calls' => 0, 'duration_ms' => 0, 'cost' => null, 'last_tokens_in' => null];
        }

        $turns = AgentTurn::query()
            ->forConversation($this->conversation)
            ->whereNotIn('status', AgentTurn::OPEN)
            ->orderByDesc('id')
            ->limit(AgentConversationStore::SUMMARY_ROWS_CAP)
            ->get(['id', 'usage', 'tool_calls', 'duration_ms', 'cost']);

        $priced = $turns->filter(fn (AgentTurn $t) => $t->cost !== null);

        return [
            'count' => $turns->count(),
            'tokens_in' => (int) $turns->sum(fn (AgentTurn $t) => $t->tokensIn() ?? 0),
            'tokens_out' => (int) $turns->sum(fn (AgentTurn $t) => $t->tokensOut() ?? 0),
            'tool_calls' => (int) $turns->sum(fn (AgentTurn $t) => count($t->tool_calls ?? [])),
            'duration_ms' => (int) $turns->sum('duration_ms'),
            'cost' => $priced->isEmpty() ? null : round((float) $priced->sum('cost'), 6), // in AgentPricing::currency(); null when no model has a price
            'last_tokens_in' => $turns->first(fn (AgentTurn $t) => $t->usage !== null)?->tokensIn(),
        ];
    }

    /**
     * Fold the older part of this chat into its rolling summary now, keeping the last exchanges verbatim: true when
     * something was compressed, false when there was nothing older (or no chat, or the agent is off). A surface
     * offers this while the chat is idle; compressing while a turn runs or a decision waits is refused as ChatBusy.
     * The summarizer's failure is thrown.
     *
     * @throws ChatBusy
     */
    public function compress(): bool
    {
        if (! $this->conversation || ! AgentModels::enabled()) {
            return false;
        }

        if (! $this->idle()) {
            throw new ChatBusy('The chat cannot be compressed while a turn runs or a decision waits for the other proposal.');
        }

        return app(AgentConversationStore::class)->compactNow($this->conversation, $this->summarizer(), AgentConversationStore::compressKeepTurns());
    }

    /** Start a new chat from a summary of this one and return its id (null when there is no chat or the agent is off). */
    public function continueInNew(): ?string
    {
        if (! $this->conversation || ! AgentModels::enabled()) {
            return null;
        }

        $title = Str::limit((string) $this->title(), 80);

        return app(AgentConversationStore::class)->continueConversation(
            $this->conversation,
            $this->participant,
            __(':title (continued)', ['title' => $title]),
            $this->summarizer(),
        );
    }

    /** A summarizer on the cheapest model of the provider behind the picked model. */
    public function summarizer(): Closure
    {
        $resolved = AgentModels::resolve($this->model);
        $agent = Agents::agent($this->context, $this->model);

        return AgentConversationStore::providerSummarizer(app(AiManager::class)->textProviderFor($agent, $resolved['provider']));
    }

    /**
     * Ask a question, with the files the person attached (laravel/ai files, AgentAttachments::store() gives one
     * per upload): the turn it became (null for an empty question or when the agent is off).
     *
     * @param  list<File>  $attachments
     */
    public function send(string $prompt, array $attachments = []): ?AgentTurn
    {
        $prompt = trim($prompt);

        if ($prompt === '' && $attachments === []) {
            return null;
        }

        return $this->startTurn(array_filter([
            'prompt' => $prompt !== '' ? $prompt : __('(see the attached file)'),
            'attachments' => array_values(array_map(fn (File $file) => $file->toArray(), $attachments)),
        ]));
    }

    /**
     * Carry on an answer the model's length limit cut short: a continuation turn, whose question a surface does
     * not show and whose answer reads as the rest of the one above. Null unless the last answer was cut short
     * that way and nothing runs.
     */
    public function continueAnswer(): ?AgentTurn
    {
        $last = $this->messages()->last();

        if (! $last || ! ($last['continuable'] ?? false)) {
            return null;
        }

        return $this->startTurn(['prompt' => 'Continue exactly where your previous answer stopped, without repeating what you already wrote.', 'continuation' => true]);
    }

    /**
     * Approve or reject a pending proposal: the decision turn, held while the other proposals of the same answer
     * wait for theirs (a second decision joins the held turn), refused while an answer runs or a question waits.
     */
    public function decide(string $callId, bool $approve): ?AgentTurn
    {
        $live = $this->live();

        if (! $this->conversation || $live['active'] !== null || $live['queued'] !== []) {
            return null;
        }

        return $this->startTurn(['decisions' => [$callId => $approve]]);
    }

    /** Send the last question again when it never got an answer. */
    public function retry(): ?AgentTurn
    {
        $last = $this->lastQuestion();

        if (! $last || ! $this->idle() || ConversationMessage::query()->where('conversation_id', $this->conversation)->where('id', '>', $last->id)->exists()) {
            return null;
        }

        return $this->startTurn(['prompt' => (string) $last->content], answering: $last->id);
    }

    /** Answer the last question again: its answer is dropped and the same recorded question is sent once more. */
    public function regenerate(): ?AgentTurn
    {
        $last = $this->lastQuestion();

        if (! $last || ! $this->idle()) {
            return null;
        }

        app(AgentConversationStore::class)->dropMessagesAfter($this->conversation, $last->id);

        return $this->startTurn(['prompt' => (string) $last->content], answering: $last->id);
    }

    /** Edit the last question and send it again: its answer is dropped, the recorded question rewritten. */
    public function resend(string $prompt): ?AgentTurn
    {
        $prompt = trim($prompt);
        $last = $this->lastQuestion();

        if ($prompt === '' || ! $last || ! $this->idle()) {
            return null;
        }

        $store = app(AgentConversationStore::class);
        $store->dropMessagesAfter($this->conversation, $last->id);
        $store->rewriteQuestion($this->conversation, $last->id, $prompt);

        return $this->startTurn(['prompt' => $prompt], answering: $last->id);
    }

    /** Stop the running turn; the job stores what it has so far. */
    public function stop(): void
    {
        if (! $this->conversation) {
            return;
        }

        $turns = app(AgentTurns::class);

        if ($active = $turns->active($this->conversation)) {
            $turns->requestStop($active);
        }
    }

    /** Take a waiting question out of the line. */
    public function removeQueued(string $turn): bool
    {
        if (! ($queued = $this->queuedTurn($turn)) || ! app(AgentTurns::class)->remove($queued)) {
            return false;
        }

        $this->live = null;

        return true;
    }

    /** Take a waiting question out of the line and hand its text back, for the composer. */
    public function editQueued(string $turn): ?string
    {
        if (! ($queued = $this->queuedTurn($turn)) || ! app(AgentTurns::class)->remove($queued)) {
            return null;
        }

        $this->live = null;

        return $queued->prompt();
    }

    /**
     * Rate an answer "up" or "down" (anything else counts as down). A message that is not in this chat is not
     * found, so a surface can pass the id from the request as is.
     *
     * @throws ModelNotFoundException
     */
    public function rate(string $messageId, string $rating, ?string $note = null): void
    {
        $owned = $this->conversation && ConversationMessage::query()
            ->where('conversation_id', $this->conversation)
            ->whereKey($messageId)
            ->exists();

        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(ConversationMessage::class, [$messageId]);
        }

        // The turn that produced the answer: the newest question turn recorded before the answer's row.
        $turn = AgentTurn::query()->forConversation($this->conversation)->whereNotNull('message_id')->where('message_id', '<', $messageId)->orderByDesc('message_id')->value('id');

        AgentMessageFeedback::query()->updateOrCreate(
            ['message_id' => $messageId, 'user_id' => $this->participant->getKey()],
            ['rating' => $rating === 'up' ? 'up' : 'down', 'note' => filled($note) ? Str::limit(trim((string) $note), 1000, '') : null, 'turn_id' => $turn],
        );
    }

    /**
     * Queue one turn — a question or a set of approval decisions — on the conversation, starting the
     * conversation on the first question.
     *
     * The budget is not checked here: the EnforceBudget middleware refuses the turn when it runs, so the
     * question is recorded first and the refusal is read under it, with a Retry, like any other failed turn.
     * On the sync driver the turn has already run when this returns, so its status says how it went.
     * $answering names an already recorded question (a retry, a regenerate, an edit).
     */
    protected function startTurn(array $input, ?string $answering = null): ?AgentTurn
    {
        if (! AgentModels::enabled()) {
            return null;
        }

        $prompt = $input['prompt'] ?? null;

        AgentModels::remember($this->model);
        $store = app(AgentConversationStore::class);

        if (! $this->conversation) {
            if ($prompt === null) {
                return null;
            }

            $this->conversation = $store->startConversation($this->participant, $prompt);
        }

        // A new chat is titled by the provider once its first answer is in (the question is the title until then).
        if ($prompt !== null && ! ConversationMessage::query()->where('conversation_id', $this->conversation)->where('role', 'assistant')->exists()) {
            $input['title'] = true;
        }

        if ($this->sync) {
            $input['sync'] = true;
        }

        $this->live = null;

        return app(AgentTurns::class)->enqueue($this->conversation, $this->participant, $input, $answering, $this->model, $this->context);
    }

    /** @see AgentTurns::rejectionResult() */
    public static function rejectionResult(): string
    {
        return AgentTurns::rejectionResult();
    }

    /**
     * The names of the tools that change data (the ones the chat wraps for approval), whatever the current role.
     *
     * @return list<string>
     */
    public static function writeToolNames(): array
    {
        return self::writeTools()->keys()->all();
    }

    /**
     * The tools that change data, keyed by name.
     *
     * @return Collection<string, object>
     */
    public static function writeTools(): Collection
    {
        return collect(Agents::toolClasses())
            ->map(fn (string $class) => app($class))
            ->reject(fn ($tool) => $tool instanceof AgentTool ? $tool->isReadOnly() : AgentTool::hasReadOnlyAnnotation($tool))
            ->keyBy(fn ($tool) => $tool->name());
    }

    /**
     * The proposal as a question the person can answer (ApprovableTool::question); a tool that is no longer
     * registered reads as its name and the first argument, phrased the same way.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function question(?object $tool, string $name, array $arguments): string
    {
        return $tool ? ApprovableTool::question($tool, $arguments) : ApprovableTool::phrase(Str::headline($name), $arguments);
    }

    /** A tool result for the proposal's fold: JSON pretty-printed, anything else as it came. */
    public static function resultText(mixed $result): ?string
    {
        if ($result === null || $result === '') {
            return null;
        }

        $decoded = is_string($result) ? json_decode($result, true) : $result;

        return is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $result;
    }

    /**
     * The parts of the history window, in the order a context popup lists them.
     *
     * @return array<string, string>
     */
    public static function breakdownLabels(): array
    {
        return [
            'summary' => __('Rolling summary'),
            'questions' => __('Questions'),
            'answers' => __('Answers'),
            'tool_calls' => __('Tool calls'),
            'tool_results' => __('Tool results'),
            'tool_results_pruned' => __('Tool results, pruned to a placeholder'),
        ];
    }

    /** A wall time for a context popup: seconds, minutes from one minute on. */
    public static function duration(int $milliseconds): string
    {
        $seconds = (int) round($milliseconds / 1000);

        return $seconds >= 60 ? __(':minutes min', ['minutes' => number_format($seconds / 60, 1)]) : __(':seconds s', ['seconds' => $seconds]);
    }

    /** What to tell the person about an answer the provider ended early (AgentTurns::cutShortReason). */
    public static function cutShortText(string $reason): string
    {
        return match ($reason) {
            'length' => __('The answer hit the model\'s length limit.'),
            'content_filter' => __('The provider\'s content filter stopped the answer.'),
            default => __('The provider closed the stream before the answer was complete.'),
        };
    }

    /**
     * A tool result carrying a `chart` key becomes a Chart.js payload (type, title, labels and datasets with the
     * package's palette).
     *
     * @return array{type: string, title: string, data: array<string, mixed>}|null
     */
    public static function chartFromResult(mixed $result): ?array
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $chart = is_array($decoded) ? ($decoded['chart'] ?? null) : null;
        if (! is_array($chart) || empty($chart['labels']) || empty($chart['datasets'])) {
            return null;
        }

        $palette = ['#f59e0b', '#8b5cf6', '#10b981', '#3b82f6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];
        $type = in_array($chart['type'] ?? 'bar', ['bar', 'line', 'pie', 'doughnut'], true) ? $chart['type'] : 'bar';
        $circular = in_array($type, ['pie', 'doughnut'], true);

        $datasets = collect($chart['datasets'])->values()->map(function (array $d, int $i) use ($palette, $type, $circular, $chart) {
            $color = $palette[$i % count($palette)];

            return array_filter([
                'label' => (string) ($d['label'] ?? ''),
                'data' => array_values($d['data'] ?? []),
                'backgroundColor' => $circular ? array_map(fn ($j) => $palette[$j % count($palette)], array_keys($chart['labels'])) : ($type === 'line' ? $color.'22' : $color.'cc'),
                'borderColor' => $circular ? '#ffffff' : $color,
                'fill' => $type === 'line' ? true : null,
                'tension' => $type === 'line' ? 0.3 : null,
            ], fn ($v) => $v !== null);
        })->all();

        return [
            'type' => $type,
            'title' => (string) ($chart['title'] ?? ''),
            'data' => ['labels' => array_values($chart['labels']), 'datasets' => $datasets],
        ];
    }

    /**
     * A show-table result (a `table` key naming a registered agent resource) becomes what a surface needs to
     * embed that resource's table with the model's filters.
     *
     * @return array{resource: string, filters: array<string, mixed>, title: string}|null
     */
    public static function tableFromResult(mixed $result): ?array
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $table = is_array($decoded) ? ($decoded['table'] ?? null) : null;
        if (! is_array($table) || ! AgentResources::has((string) ($table['resource'] ?? ''))) {
            return null;
        }

        return ['resource' => $table['resource'], 'filters' => (array) ($table['filters'] ?? []), 'title' => (string) ($table['title'] ?? '')];
    }

    /** The person's conversations. */
    public function ownConversations(): Builder
    {
        return Conversation::query()
            ->where('participant_type', $this->participant->getMorphClass())
            ->where('participant_id', $this->participant->getKey());
    }

    /** The last question of the conversation. */
    protected function lastQuestion(): ?ConversationMessage
    {
        if (! $this->conversation) {
            return null;
        }

        return ConversationMessage::query()->where('conversation_id', $this->conversation)->where('role', 'user')->orderByDesc('id')->first();
    }

    protected function queuedTurn(string $id): ?AgentTurn
    {
        if (! $this->conversation) {
            return null;
        }

        return AgentTurn::query()->whereKey($id)->forConversation($this->conversation)->where('status', AgentTurn::QUEUED)->first();
    }
}
