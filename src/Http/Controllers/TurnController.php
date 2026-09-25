<?php

namespace Packstub\Agents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packstub\Agents\Support\AgentTurns;

/**
 * What the chat page polls while an answer is produced (and, slowly, while
 * it is open): the running turn's answer so far, rendered, and a version
 * stamp that changes whenever the conversation did — so a page that was
 * reopened, reloaded or opened in a second tab shows the same thing.
 * Registered on the panel's authenticated (tenant) routes by AgentsPlugin, or —
 * without one — by the service provider under chat.path and chat.middleware.
 */
class TurnController
{
    public function __invoke(Request $request, AgentTurns $turns): JsonResponse
    {
        // Read by name: in a panel with tenancy the first route parameter is the tenant.
        $conversation = (string) $request->route('conversation');

        abort_if(! $turns->owned($conversation, auth()->user()), 404);

        return response()
            ->json($turns->state($conversation))
            ->header('Cache-Control', 'no-store');
    }
}
