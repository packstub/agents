<?php

namespace Packstub\Agents\Channels\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentAnswer;
use Packstub\Agents\Support\Markdown;

/**
 * The assistant's answer, back to the person who mailed the question. The
 * subject keeps the chat's tag ("[chat 0199a1b2]") and the Message-ID names
 * the conversation, so a reply — by subject or by threading — continues
 * the same chat. Proposals the model left waiting are listed with a note
 * that they need a decision in the chat.
 */
class AgentAnswerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public InboundEmail $question, public AgentAnswer $answer, public ?string $chatUrl = null) {}

    public function envelope(): Envelope
    {
        $subject = trim((string) preg_replace('/^\s*(re|aw|fwd?)\s*:\s*/i', '', $this->question->subject)) ?: __('Your question');
        $tag = EmailChannel::tag((string) $this->answer->conversation);
        $from = config('packstub-agents.email.from');

        return new Envelope(
            from: $from ? $from : null,
            subject: 'Re: '.(str_contains($subject, $tag) ? $subject : "{$subject} {$tag}"),
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: EmailChannel::messageId((string) $this->answer->conversation, (string) $this->answer->turn?->id),
            references: array_values(array_filter([$this->question->messageId])),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'packstub-agents-mail::mail.answer',
            with: [
                'name' => Agents::name(),
                'html' => Markdown::render($this->answer->text),
                'text' => $this->answer->text,
                'proposals' => $this->answer->proposals,
                'chatUrl' => $this->chatUrl,
                'failed' => ! $this->answer->ok(),
                'error' => $this->answer->error(),
            ],
        );
    }
}
