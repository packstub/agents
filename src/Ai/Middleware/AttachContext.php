<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Ai\Agent;

/**
 * The dynamic block (date and time, workspace, person, language, page
 * context) rides with the question instead of the system prompt, so the
 * instructions, the tool list and the history in front of it are
 * byte-identical from one turn to the next and the provider's prompt cache
 * keeps hitting. It runs last: the app's middleware reads the question as
 * typed. The block is written once per turn and put on the question again
 * on every step of it, so the model reads the same messages while it calls
 * tools. A turn that resumes an approval has no question to carry it and
 * goes without — the model continues the step the block already informed.
 */
class AttachContext
{
    /** @var array<string, string|false> The block of each turn seen (by invocation), or false when the turn carries no question. */
    protected array $blocks = [];

    public function __construct(protected ?Agent $agent = null) {}

    public function handle(PendingStep $step, Closure $next)
    {
        $key = (string) ($step->invocationId ?? '');

        if ($step->isFirstStep() || ! array_key_exists($key, $this->blocks)) {
            $this->blocks[$key] = $this->agent !== null && $step->isFirstStep() && EnforceBudget::question($step) !== null
                ? $this->agent->dynamicInstructions()
                : false;
        }

        $block = $this->blocks[$key];

        if ($block === false || trim($block) === '') {
            return $next($step);
        }

        $messages = $step->messages;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($message instanceof Message && $message->role === MessageRole::User) {
                $content = $block.PHP_EOL.PHP_EOL.$message->content;
                $messages[$i] = $message instanceof UserMessage
                    ? new UserMessage($content, $message->attachments)
                    : new Message(MessageRole::User, $content);

                break;
            }
        }

        return $next($step->withMessages($messages));
    }
}
