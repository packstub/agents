<?php

namespace Packstub\Agents\Channels\Email;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentAnswer;
use Packstub\Agents\Support\AgentRun;

/**
 * The assistant by email. A mail from a person of the app becomes a
 * question asked as them (their role, their budget, their conversations);
 * the answer goes back as a reply, and a reply to that reply continues the
 * same chat — by the tag in the subject ("[chat 0199a1b2]") or by the
 * threading headers. A mail from an address that is nobody's is dropped
 * without an answer, so the webhook cannot be used to reach the assistant
 * from outside. Write tools the model proposes wait in the chat for a
 * decision: nothing changes from an email alone.
 */
class EmailChannel
{
    public static function enabled(): bool
    {
        return (bool) config('packstub-agents.email.enabled', false) && filled(config('packstub-agents.email.secret'));
    }

    /**
     * Answer a mail: null when the sender is nobody, or the mail is empty. $chatUrl, when given, is linked at the
     * end of the reply (fn (string $conversation): ?string).
     */
    public static function receive(InboundEmail $mail, ?callable $chatUrl = null): ?AgentAnswer
    {
        $participant = Agents::participantByEmail($mail->from);

        if (! $participant || trim($mail->text) === '') {
            return null;
        }

        $tenant = null;

        if ($mail->tenant !== null) {
            $context = Agents::context();
            $tenant = $context->findTenantBySlug($mail->tenant) ?? $context->findTenant($mail->tenant);
        }

        $conversation = self::conversationOf($mail, $participant);
        $prompt = self::body($mail);

        $answer = AgentRun::as($participant)->in($tenant)->continuing($conversation)->ask($prompt);

        $url = $chatUrl && $answer->conversation ? $chatUrl($answer->conversation) : null;
        Mail::to($mail->from)->send(new AgentAnswerMail($mail, $answer, $url));

        return $answer;
    }

    /** The question: the text without a quoted reply below it ("On … wrote:", "> "), and without a signature. */
    public static function body(InboundEmail $mail): string
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $mail->text) ?: [] as $line) {
            if (rtrim($line) === '--' || preg_match('/^(On .+ wrote:|Am .+ schrieb .+:|El .+ escribió:|-----\s*Original Message\s*-----)/u', trim($line)) === 1 || str_starts_with(ltrim($line), '>')) {
                break;
            }

            $lines[] = $line;
        }

        $text = trim(implode("\n", $lines));

        return $text !== '' ? $text : trim($mail->text);
    }

    /** The conversation a mail continues — the person's, by the subject tag or the threading headers — or null for a new one. */
    public static function conversationOf(InboundEmail $mail, Model&Authenticatable $participant): ?string
    {
        $short = null;
        $full = null;

        if (preg_match('/\[chat ([0-9a-f]{8})\]/i', $mail->subject, $m) === 1) {
            $short = strtolower($m[1]);
        }

        foreach ([$mail->inReplyTo, $mail->references] as $header) {
            if ($header !== null && preg_match('/chat-([0-9a-f-]{36})\./i', $header, $m) === 1) {
                $full = strtolower($m[1]);
            }
        }

        $own = Conversation::query()
            ->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey());

        if ($full !== null && (clone $own)->whereKey($full)->exists()) {
            return $full;
        }

        if ($short !== null) {
            return (clone $own)->where('id', 'like', $short.'%')->orderByDesc('updated_at')->value('id');
        }

        return null;
    }

    /** The subject tag naming a conversation: its first eight characters. */
    public static function tag(string $conversation): string
    {
        return '[chat '.Str::substr($conversation, 0, 8).']';
    }

    /** The Message-ID of a reply, naming the conversation for the threading headers of the next mail. */
    public static function messageId(string $conversation, string $turn): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'agents.local';

        return "chat-{$conversation}.{$turn}@{$host}";
    }
}
