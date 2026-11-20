<?php

namespace Packstub\Agents\Chat\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Chat\ChatHost;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;

/**
 * <x-agents-chats /> — the signed-in person's chats: pinned first, the latest
 * on top, a search box over titles and messages (?q=), a page at a time.
 */
class Chats extends Component
{
    public function __construct(public int $perPage = 20, public bool $assets = true) {}

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof Model, 403);

        $host = app(ChatHost::class);
        $query = trim((string) request()->query('q'));
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';
        $pinned = AgentChat::pinnedIds($user);

        $chats = AgentConversationStore::conversationsOf($user)
            // whereLike, case-insensitive: a plain like is case-sensitive on Postgres.
            ->when($query !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereLike('title', $like, caseSensitive: false)
                ->orWhereIn('id', ConversationMessage::query()->select('conversation_id')->whereLike('content', $like, caseSensitive: false))))
            ->when($pinned !== [], fn (Builder $q) => $q->orderByRaw('case when id in ('.implode(',', array_fill(0, count($pinned), '?')).') then 0 else 1 end', $pinned))
            ->orderByDesc('updated_at')
            ->simplePaginate($this->perPage)
            ->withQueryString();

        return view('packstub-agents-chat::chats', [
            'host' => $host,
            'ui' => $host->kit(),
            'chats' => $chats,
            'pinned' => $pinned,
            'query' => $query,
            'assets' => $this->assets,
        ]);
    }
}
