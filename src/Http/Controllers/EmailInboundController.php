<?php

namespace Packstub\Agents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;

/**
 * POST {chat.path}/email, behind the shared secret: the mail provider's
 * webhook, in its own field names or the plain ones (from, subject, text,
 * message_id, in_reply_to, references, tenant). Always 200 once
 * authenticated, so the provider does not retry a mail that was nobody's.
 */
class EmailInboundController
{
    public function __invoke(Request $request): JsonResponse
    {
        $answer = EmailChannel::receive(InboundEmail::fromArray($request->all()));

        return response()->json([
            'answered' => $answer !== null && $answer->ok(),
            'conversation' => $answer?->conversation,
            'turn' => $answer?->turn?->id,
        ]);
    }
}
