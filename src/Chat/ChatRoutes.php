<?php

namespace Packstub\Agents\Chat;

use Illuminate\Support\Facades\Route;
use Packstub\Agents\Http\Controllers\ChatController;
use Packstub\Agents\Http\Controllers\TurnController;
use Packstub\Agents\Http\Controllers\TurnStreamController;

/**
 * The chat's endpoints, registered inside whatever group the caller opened:
 * the service provider under chat.path and chat.middleware, Filament Agents
 * on a panel's authenticated (tenant) routes. The names are the same in both
 * (packstub-agents.turn, .stream, .act, .view, .mentions, .export); a panel
 * prefixes them with its own.
 */
class ChatRoutes
{
    public static function endpoints(string $prefix): void
    {
        $prefix = trim($prefix, '/');

        Route::get($prefix.'/chat/mentions', [ChatController::class, 'mentions'])->name('packstub-agents.mentions');
        Route::post($prefix.'/chat/view', [ChatController::class, 'refresh'])->name('packstub-agents.view');
        Route::post($prefix.'/chat/act/{action}', [ChatController::class, 'act'])->name('packstub-agents.act');
        Route::get($prefix.'/chat/{conversation}/turn', TurnController::class)->name('packstub-agents.turn');
        Route::get($prefix.'/chat/{conversation}/stream', TurnStreamController::class)->name('packstub-agents.stream');
        Route::get($prefix.'/chat/{conversation}/export', [ChatController::class, 'export'])->name('packstub-agents.export');
    }

    /** The package's own pages for a plain app (chat.ui.page): the chat and the list of chats. */
    public static function pages(string $prefix): void
    {
        $prefix = trim($prefix, '/');

        Route::get($prefix.'/chats', [ChatController::class, 'chats'])->name('packstub-agents.chats');
        Route::get($prefix.'/chat/{conversation?}', [ChatController::class, 'page'])->name('packstub-agents.chat');
    }
}
