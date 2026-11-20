<?php

namespace Packstub\Agents\Chat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentTurns;

/**
 * One chat as its views read it: the engine's AgentChat for the person, the
 * host it is rendered in, and what the page was opened with (the model, the
 * record it is about, the slide-over, a question waiting in the composer).
 * open() is the first render of a page; make() is every render after it (an
 * action's answer, the refresh when a turn ends), from what the page sent.
 */
class ChatView
{
    public function __construct(
        public readonly ChatHost $host,
        public readonly AgentChat $chat,
        public readonly bool $embedded = false,
        public readonly string $prompt = '',
        public readonly bool $autoSend = false,
        /** The record the page was opened about; the chat's own context once it has a conversation. */
        public readonly ?string $context = null,
    ) {}

    /**
     * A page opens the chat: another person's conversation is not found, a first question the app held back starts
     * now, and a question the app left in the session is sent on load (one from the URL only waits in the composer:
     * anyone can craft a link).
     */
    public static function open(?string $conversation = null, ?string $context = null, bool $embedded = false, ?string $prompt = null): static
    {
        $user = static::user();

        if ($conversation !== null) {
            abort_unless(AgentChat::for($user)->owns($conversation), 404);
            app(AgentTurns::class)->startDeferred($conversation, $user);
        }

        $autoSend = false;

        if ($pulled = session()->pull('packstub-agents.prompt')) {
            $prompt = (string) $pulled;
            $autoSend = true;
        }

        return static::make($conversation, null, $context, $embedded, (string) $prompt, $autoSend);
    }

    /** The chat as the page describes it. A conversation that is not the person's is not found. */
    public static function make(?string $conversation = null, ?string $model = null, ?string $context = null, bool $embedded = false, string $prompt = '', bool $autoSend = false): static
    {
        $model = $model !== null && array_key_exists($model, AgentModels::options()) ? $model : null;

        return new static(app(ChatHost::class), AgentChat::for(static::user(), $conversation ?: null, $model, $context ?: null), $embedded, $prompt, $autoSend, $context ?: null);
    }

    /** @return Model&Authenticatable */
    protected static function user(): Model
    {
        $user = auth()->user();

        abort_unless($user instanceof Model, 403);

        return $user;
    }

    /**
     * The chat's HTML. $have is what the page already shows (part key => revision): those parts come back as stubs.
     *
     * @param  array<string, string>  $have
     */
    public function render(array $have = []): string
    {
        app(ChatParts::class)->known($have);

        try {
            return view('packstub-agents-chat::chat', $this->data())->render();
        } finally {
            app(ChatParts::class)->known([]);
        }
    }

    public function title(): string
    {
        return $this->chat->conversation() ? (string) ($this->chat->title() ?? __('Chat')) : __('New chat');
    }

    /** One of the conversation's endpoints, null before the first question of a new chat. */
    public function conversationUrl(string $endpoint): ?string
    {
        $conversation = $this->chat->conversation();

        return $conversation ? $this->host->endpoint($endpoint, ['conversation' => $conversation]) : null;
    }

    /**
     * Where New chat goes. In the slide-over it stays about the record the chat is about (the context it was opened
     * with, or its conversation's), as the slide-over of a record page opens; on the full page it starts afresh.
     */
    public function newChatUrl(): string
    {
        if (! $this->embedded) {
            return $this->host->chatUrl();
        }

        return $this->host->chatUrl(null, ['embedded' => 1, 'context' => $this->context ?? $this->chat->context()]);
    }

    /** The full-page URL of this chat (the slide-over links to it). */
    public function pageUrl(): string
    {
        $conversation = $this->chat->conversation();

        return $this->host->chatUrl($conversation, ['context' => $conversation ? null : $this->context]);
    }

    /**
     * What the composer's attach button accepts (null when attachments are off).
     *
     * @return array{accept: string, maxKb: int, maxFiles: int}|null
     */
    public function attachments(): ?array
    {
        if (! AgentAttachments::enabled()) {
            return null;
        }

        return ['accept' => implode(',', AgentAttachments::mimes()), 'maxKb' => AgentAttachments::maxKilobytes(), 'maxFiles' => AgentAttachments::maxPerQuestion()];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $chat = $this->chat->refresh();
        $live = $chat->live();
        $messages = $chat->messages();
        $lastQuestionId = $messages->last(fn ($m) => $m['role'] === 'user' && ! $m['continuation'])['id'] ?? null;
        $suggestions = $chat->suggestions();
        $attachments = $this->attachments();

        return [
            'view' => $this,
            'host' => $this->host,
            'ui' => $this->host->kit(),
            'chat' => $chat,
            'conversation' => $chat->conversation(),
            'embedded' => $this->embedded,
            'model' => $chat->model(),
            'title' => $this->title(),
            'live' => $live,
            'history' => $chat->history(),
            'messages' => $messages,
            'lastQuestionId' => $lastQuestionId,
            'lastQuestionVersions' => $lastQuestionId ? $messages->firstWhere('id', $lastQuestionId)['versions'] : 0,
            // A conversation on the page (or on its way): the composer moves to the bottom of the window.
            'started' => $messages->isNotEmpty() || filled($live['active']) || filled($live['queued']),
            'suggestions' => $suggestions,
            'state' => [
                'prompt' => $this->prompt,
                'auto-send' => $this->autoSend ? '1' : '',
                'conversation' => (string) $chat->conversation(),
                'model' => $chat->model(),
                'context' => (string) $this->context,
                'embedded' => $this->embedded ? '1' : '',
                'act' => $this->host->endpoint('act', ['action' => '__action__']),
                'view' => $this->host->endpoint('view'),
                'search' => $this->host->canMention() ? $this->host->endpoint('mentions') : '',
                'poll' => (string) $this->conversationUrl('turn'),
                'stream' => (string) $this->conversationUrl('stream'),
                'active' => (string) ($live['active']['id'] ?? ''),
                'interval' => (string) AgentTurns::pollInterval(),
                'title' => $this->title(),
                'suggestions' => (string) json_encode($suggestions),
                'csrf' => (string) csrf_token(),
                'toasts' => $this->host->notifies() ? '' : '1',
                'copied' => __('Copied'),
                'copy' => __('Copy'),
                'expired' => __('This page has expired. Reload it to carry on.'),
                'failed' => __('That did not go through. Check your connection and try again.'),
                'too-many' => $attachments ? trans_choice('{1} One file per question.|[2,*] Up to :count files per question.', $attachments['maxFiles']) : '',
                'too-big' => $attachments ? __('A file can be up to :size MB.', ['size' => round($attachments['maxKb'] / 1024, 1)]) : '',
            ],
        ];
    }
}
