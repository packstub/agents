<?php

namespace Packstub\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packstub\Agents\Channels\Email\EmailChannel;
use Symfony\Component\HttpFoundation\Response;

/** The inbound mail webhook must carry the shared secret (X-Agent-Secret, or ?secret=) — else 401, as JSON; 404 while the channel is off. */
class AuthenticateEmailWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EmailChannel::enabled()) {
            return response()->json(['message' => 'The email channel is off.'], 404);
        }

        $secret = (string) config('packstub-agents.email.secret');
        $given = (string) ($request->header('X-Agent-Secret') ?? $request->query('secret', ''));

        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
