<?php

namespace Packstub\Agents\Channels\Email;

/**
 * One mail as the webhook of your mail provider hands it over, normalized:
 * who wrote, the subject, the text (the plain part; a reply's quoted history
 * stripped by the provider when it can), the Message-ID, and what it replies
 * to — so a reply continues the same chat.
 */
final class InboundEmail
{
    public function __construct(
        public readonly string $from,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
        public readonly ?string $references = null,
        public readonly ?string $tenant = null,
    ) {}

    /**
     * From the fields the webhook posts (from, subject, text, message_id, in_reply_to, references, tenant); a
     * provider's own names (From, Subject, TextBody / stripped-text / body-plain, MessageID, Headers…) are read too.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $pick = function (array $keys) use ($payload): ?string {
            foreach ($keys as $key) {
                if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                    return trim((string) $payload[$key]);
                }
            }

            return null;
        };

        $from = $pick(['from', 'From', 'sender', 'FromFull.Email']) ?? (string) ($payload['FromFull']['Email'] ?? '');

        return new self(
            from: self::address($from),
            subject: $pick(['subject', 'Subject']) ?? '',
            text: $pick(['text', 'stripped-text', 'StrippedTextReply', 'TextBody', 'body-plain', 'body']) ?? '',
            messageId: $pick(['message_id', 'MessageID', 'Message-Id', 'Message-ID']),
            inReplyTo: $pick(['in_reply_to', 'In-Reply-To']),
            references: $pick(['references', 'References']),
            tenant: $pick(['tenant']),
        );
    }

    /** The bare address out of "Ada Lovelace <ada@example.com>". */
    public static function address(string $from): string
    {
        return strtolower(trim(preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from));
    }
}
