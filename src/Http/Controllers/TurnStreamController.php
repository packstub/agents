<?php

namespace Packstub\Agents\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Support\AgentTurns;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The same state the poll endpoint returns, pushed as server-sent events
 * while the answer is produced: one `turn` event whenever the running
 * turn's text, status or tools changed or the conversation did (the version
 * stamp), a comment line as a heartbeat otherwise. The stream closes when
 * the turn ends (one last event says so) or after chat.stream_seconds, and
 * the browser's EventSource reconnects on its own; a client that cannot hold
 * it open falls back to polling. Registered next to the poll route.
 */
class TurnStreamController
{
    public function __invoke(Request $request, AgentTurns $turns): StreamedResponse
    {
        $conversation = (string) $request->route('conversation');

        abort_if(! $turns->owned($conversation, auth()->user()), 404);

        $interval = max(50, (int) config('packstub-agents.chat.stream_interval', 150)) * 1000;
        $deadline = microtime(true) + max(5, (int) config('packstub-agents.chat.stream_seconds', 55));
        $lastSeen = $request->query('version');

        return response()->stream(function () use ($turns, $conversation, $interval, $deadline, $lastSeen): void {
            // The session is not needed past this point, and a locked one would block the page's own requests.
            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            $last = null;
            $beat = microtime(true);

            while (microtime(true) < $deadline) {
                $state = $turns->state($conversation);
                $stamp = md5(json_encode([$state['version'], $state['active']['id'] ?? null, $state['active']['statusText'] ?? null, $state['active']['html'] ?? null, $state['active']['tools'] ?? null]));

                if ($stamp !== $last && ! ($last === null && $lastSeen === $state['version'] && $state['active'] === null)) {
                    echo "event: turn\ndata: ".json_encode($state, JSON_UNESCAPED_UNICODE)."\n\n";
                    $last = $stamp;
                    $beat = microtime(true);
                } elseif (microtime(true) - $beat >= 15) {
                    echo ": keep-alive\n\n";
                    $beat = microtime(true);
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if ($state['active'] === null && $last !== null) {
                    echo "event: end\ndata: {}\n\n";
                    flush();

                    return;
                }

                if (connection_aborted()) {
                    return;
                }

                usleep($interval);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
