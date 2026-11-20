<?php

namespace Packstub\Agents\Chat\Hosts;

use Illuminate\Support\Facades\Route;
use LogicException;
use Packstub\Agents\Chat\ChatHost;

/**
 * The chat in a plain Laravel app: its endpoints are the package's named
 * routes (chat.path, chat.middleware), its page is the route the app names in
 * chat.ui.route — or the package's own page, with chat.ui.page on — and its
 * buttons and icons are the package's Blade components.
 */
class RouteHost implements ChatHost
{
    public function chatUrl(?string $conversation = null, array $query = []): string
    {
        $name = (string) (config('packstub-agents.chat.ui.route') ?: 'packstub-agents.chat');

        if (! Route::has($name)) {
            throw new LogicException('The chat needs a page: set packstub-agents.chat.ui.route to the name of your route (with an optional {conversation} parameter), or turn on packstub-agents.chat.ui.page.');
        }

        return route($name, array_filter(['conversation' => $conversation, ...$query], fn ($value) => $value !== null && $value !== ''));
    }

    public function chatsUrl(): ?string
    {
        $name = (string) (config('packstub-agents.chat.ui.chats_route') ?: 'packstub-agents.chats');

        return Route::has($name) ? route($name) : null;
    }

    public function endpoint(string $name, array $parameters = []): string
    {
        return route('packstub-agents.'.$name, $parameters);
    }

    public function canMention(): bool
    {
        return false;
    }

    public function mentions(string $query): array
    {
        return [];
    }

    public function kit(): string
    {
        return 'packstub-agents-chat';
    }

    public function embedView(string $kind): ?string
    {
        return $kind === 'chart' ? 'packstub-agents-chat::embeds.chart' : null;
    }

    public function slot(string $name): string
    {
        return '';
    }

    public function notifies(): bool
    {
        return false;
    }
}
