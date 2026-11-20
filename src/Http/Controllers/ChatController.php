<?php

namespace Packstub\Agents\Http\Controllers;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packstub\Agents\Chat\ChatHost;
use Packstub\Agents\Chat\ChatView;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentModels;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * What the chat page calls: an action on the conversation (send, retry,
 * approve, stop, rate …), the rendered chat when a turn ends, the records
 * "@" offers, the Markdown export — and, for an app that turned them on, the
 * chat page and the list of chats. Every call is the signed-in person's own:
 * a conversation that is not theirs is not found. Registered under chat.path
 * and chat.middleware, or by Filament Agents on the panel's authenticated
 * (tenant) routes.
 */
class ChatController
{
    /** The notices of this request, shown by the page as toasts (or by the host). */
    protected array $notices = [];

    /**
     * POST …/chat/act/{action}. The body names the conversation (none for a new chat), the model, the record the
     * chat is about and what the action needs; the answer carries the turn to listen to, the notices, a redirect,
     * and the chat rendered again (`have` lists the parts the page already shows).
     */
    public function act(Request $request): JsonResponse
    {
        // Read by name: in a panel with tenancy the first route parameter is the tenant.
        $action = (string) $request->route('action');

        // A controller can outlive a request (Octane keeps the route's instance): start clean.
        $this->notices = [];

        $view = $this->view($request);
        $chat = $view->chat;
        $out = [];

        match ($action) {
            'send' => $out = $this->send($request, $view),
            'resend' => $out = $this->started($view, fn (AgentChat $chat) => $chat->resend((string) $request->input('prompt')), question: true),
            'regenerate' => $out = $this->started($view, fn (AgentChat $chat) => $chat->regenerate(), question: true),
            'continue' => $out = $this->started($view, fn (AgentChat $chat) => $chat->continueAnswer(), question: true),
            'retry' => $out = $this->started($view, fn (AgentChat $chat) => $chat->retry($request->filled('message') ? (string) $request->input('message') : null), question: true),
            'decide' => $out = $this->started($view, fn (AgentChat $chat) => $chat->decide((string) $request->input('call'), $request->boolean('approve'))),
            'stop' => $chat->stop(),
            'queued-remove' => $chat->removeQueued((string) $request->input('turn')),
            'queued-edit' => $out = ['text' => $chat->editQueued((string) $request->input('turn'))],
            'feedback' => $this->feedback($request, $chat),
            'version' => $chat->showVersion((string) $request->input('question'), (int) $request->input('version')) || $this->notify('warning', __('That answer cannot be shown right now.')),
            'rename' => $chat->rename((string) $request->input('title')),
            'pin' => $chat->pinned() ? $chat->unpin() : $chat->pin(),
            'compress' => $this->compress($chat),
            'continue-in-new' => $out = $this->continueInNew($view),
            default => abort(404),
        };

        return response()
            ->json([
                ...$out,
                'notices' => $this->notices,
                // A redirect leaves the page: nothing to draw.
                'html' => isset($out['redirect']) ? null : $view->render($this->have($request)),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /** POST …/chat/view — the chat as it stands, for the page to morph in when a turn ended or another tab moved it on. */
    public function refresh(Request $request): JsonResponse
    {
        return response()
            ->json(['html' => $this->view($request)->render($this->have($request))])
            ->header('Cache-Control', 'no-store');
    }

    /** GET …/chat/mentions?q= — the records "@…" offers. */
    public function mentions(Request $request, ChatHost $host): JsonResponse
    {
        abort_unless($request->user() !== null, 403);

        return response()->json($host->canMention() ? $host->mentions(trim((string) $request->query('q'))) : []);
    }

    /** GET …/chat/{conversation}/export — the chat as a Markdown file. */
    public function export(Request $request): StreamedResponse
    {
        $view = $this->view($request, (string) $request->route('conversation'));
        $transcript = $view->chat->transcript();
        $name = Str::slug(Str::limit($view->title(), 60, ''), '-') ?: 'chat';

        return response()->streamDownload(fn () => print $transcript, "{$name}.md", ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    /** GET …/chat/{conversation?} — the package's own chat page (chat.ui.page), in the app's layout. */
    public function page(Request $request): View
    {
        abort_unless(AgentModels::enabled(), 404);

        // Read by name: under a tenant prefix the first route parameter is the tenant.
        return view('packstub-agents-chat::page', [
            'layout' => (string) config('packstub-agents.chat.ui.layout'),
            'chat' => ChatView::open(
                $request->route('conversation') ? (string) $request->route('conversation') : null,
                $request->query('context') ? (string) $request->query('context') : null,
                (bool) $request->query('embedded'),
                is_string($request->query('prompt')) ? $request->query('prompt') : null,
            ),
        ]);
    }

    /** GET …/chats — the person's chats, pinned first, the latest on top. */
    public function chats(Request $request, ChatHost $host): View
    {
        abort_unless(AgentModels::enabled() && $request->user() !== null, 404);

        return view('packstub-agents-chat::chats-page', ['layout' => (string) config('packstub-agents.chat.ui.layout')]);
    }

    /** The chat the request is about. The conversation comes from the body (or the route, by name). */
    protected function view(Request $request, ?string $conversation = null): ChatView
    {
        $conversation ??= $request->filled('conversation') ? (string) $request->input('conversation') : null;

        try {
            return ChatView::make(
                $conversation,
                $request->filled('model') ? (string) $request->input('model') : null,
                $request->filled('context') ? (string) $request->input('context') : null,
                $request->boolean('embedded'),
            );
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    /** @return array<string, string> */
    protected function have(Request $request): array
    {
        $have = $request->input('have', []);

        // A multipart body (a question with files) carries it as JSON.
        if (is_string($have)) {
            $have = json_decode($have, true);
        }

        return is_array($have) ? array_filter($have, 'is_string') : [];
    }

    protected function notify(string $level, string $title, ?string $body = null, bool $persistent = false): bool
    {
        $this->notices[] = ['level' => $level, 'title' => $title, 'body' => $body, 'persistent' => $persistent];

        return true;
    }

    /** The question, the records it mentions (refs such as "orders/12") and the files picked in the composer. */
    protected function send(Request $request, ChatView $view): array
    {
        $prompt = trim((string) $request->input('prompt'));
        $mentions = $request->input('mentions', []);
        $mentions = is_string($mentions) ? json_decode($mentions, true) : $mentions;
        $files = [];

        if (AgentAttachments::enabled()) {
            try {
                foreach (array_slice(array_values((array) $request->file('files', [])), 0, AgentAttachments::maxPerQuestion()) as $upload) {
                    if ($upload instanceof UploadedFile) {
                        $files[] = AgentAttachments::store($upload);
                    }
                }
            } catch (InvalidArgumentException $e) {
                $this->notify('warning', $e->getMessage());

                return [];
            }
        }

        if ($prompt === '' && $files === []) {
            return [];
        }

        return $this->started($view, fn (AgentChat $chat) => $chat->send($prompt, $files, array_values(array_filter((array) $mentions, 'is_string'))), question: true);
    }

    /** Rate an answer; a thumbs-down may carry a note on what went wrong. */
    protected function feedback(Request $request, AgentChat $chat): void
    {
        $rating = (string) $request->input('rating');

        $chat->rate((string) $request->input('message'), $rating, $rating === 'down' && $request->filled('note') ? (string) $request->input('note') : null);
    }

    /** Fold the older part of the chat into its rolling summary now, keeping the last exchanges verbatim. */
    protected function compress(AgentChat $chat): void
    {
        if (! $chat->conversation() || ! AgentModels::enabled() || ! $chat->idle()) {
            return;
        }

        try {
            $compressed = $chat->compress();
        } catch (Throwable $e) {
            report($e);
            $this->notify('danger', __('The chat could not be summarized'), $e->getMessage());

            return;
        }

        $this->notify(
            'success',
            $compressed ? __('Older messages were compressed') : __('Nothing older to compress'),
            $compressed ? __('The assistant reads a summary of them from the next question on.') : __('The last exchanges are always kept as they are.'),
        );
    }

    /** Open a new chat that starts from a summary of this one, and send the page there. */
    protected function continueInNew(ChatView $view): array
    {
        if (! $view->chat->conversation() || ! AgentModels::enabled()) {
            return [];
        }

        try {
            $id = $view->chat->continueInNew();
        } catch (Throwable $e) {
            report($e);
            $this->notify('danger', __('The chat could not be summarized'), $e->getMessage());

            return [];
        }

        return $id === null ? [] : ['redirect' => $view->host->chatUrl($id)];
    }

    /**
     * Queue one turn through the engine and tell the page what to listen to. A new chat keeps the page (queued
     * questions would not survive a reload) and takes the conversation's URL. On the sync driver the turn already
     * ran inside this request: a failure is said now.
     *
     * @param  Closure(AgentChat): ?AgentTurn  $start
     */
    protected function started(ChatView $view, Closure $start, bool $question = false): array
    {
        if (! AgentModels::enabled()) {
            $this->notify('warning', __(':name is not connected to an AI provider yet.', ['name' => Agents::name()]));

            return [];
        }

        $chat = $view->chat;
        $new = $chat->conversation() === null;
        $turn = $start($chat);

        if (! $turn) {
            return [];
        }

        if ($turn->status === AgentTurn::FAILED) {
            $kept = $question ? ' '.__('Your question is kept — use Retry to send it again.') : '';

            if ($turn->finish_reason === 'refused') {
                $this->notify('warning', (string) $turn->error, trim($kept) ?: null);
            } else {
                $this->notify('danger', __('The assistant could not answer'), $turn->error.$kept, persistent: true);
            }
        }

        return [
            'turn' => [
                'id' => $turn->id,
                'conversation' => $chat->conversation(),
                'poll' => $view->conversationUrl('turn'),
                'stream' => $view->conversationUrl('stream'),
                'active' => $turn->isActive(),
            ],
            // The address bar follows a new chat to its own URL; the slide-over's frame keeps its own.
            'url' => $new && ! $view->embedded ? $view->host->chatUrl($chat->conversation()) : null,
        ];
    }
}
