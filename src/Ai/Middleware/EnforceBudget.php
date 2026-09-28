<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Support\AgentBudget;

/**
 * The spending guard rails, on every turn whatever asked for it: the chat
 * page's job, a console command, an app that prompts the agent directly. A
 * turn over a limit is refused with the reason before the provider is
 * called; one that may run is counted against the per-minute limit. laravel/ai
 * runs middleware around each model round-trip of a turn, so the check is
 * made on the first step only: the tool steps that follow belong to the same
 * turn.
 */
class EnforceBudget
{
    public function handle(PendingStep $step, Closure $next)
    {
        if ($step->isFirstStep()) {
            if ($refusal = AgentBudget::refusal(self::question($step))) {
                throw new TurnRefused($refusal);
            }

            AgentBudget::hit();
        }

        return $next($step);
    }

    /** The question the turn sends, as typed; null for a turn that resumes a proposal (its last message is a tool result). */
    public static function question(PendingStep $step): ?string
    {
        $last = $step->messages === [] ? null : $step->messages[array_key_last($step->messages)];

        return $last instanceof Message && $last->role === MessageRole::User ? (string) $last->content : null;
    }
}
