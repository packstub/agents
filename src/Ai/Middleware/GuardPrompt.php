<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Ai\Side\GuardAgent;
use Packstub\Agents\Events\PromptFlagged;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Facades\Agents;
use Throwable;

/**
 * The prompt guard (config `prompt_guard`, off by default): before the
 * assistant reads a question, the GuardAgent side agent — a small model, or
 * a local one — classifies it as safe, injection, jailbreak,
 * data_exfiltration or off_topic. A category on the `refuse` list stops the
 * turn with a friendly message under the question (TurnRefused: nothing
 * runs, nothing is billed by the assistant's model); anything but safe is
 * logged with its reason and fires PromptFlagged. It runs on the first step
 * of a turn only, on the question as typed; a turn that resumes a proposal
 * carries no question and passes. When the classifier itself fails the turn
 * runs (`fail_open`), since the tools' own ability checks and approvals are
 * still in place; with `fail_open` off it refuses, except when the provider
 * itself refused the call (no credits, rate limited, overloaded,
 * unreachable): that error ends the turn as it would at the assistant's step.
 */
class GuardPrompt
{
    public function __construct(protected ?Agent $agent = null) {}

    public static function enabled(): bool
    {
        return (bool) config('packstub-agents.prompt_guard.enabled', false);
    }

    public function handle(PendingStep $step, Closure $next)
    {
        if (! self::enabled() || ! $step->isFirstStep() || ($question = EnforceBudget::question($step)) === null || trim($question) === '') {
            return $next($step);
        }

        // With the assistant faked in a test the guard runs only when it is faked too.
        if ($this->agent !== null && ! GuardAgent::runsBeside($this->agent)) {
            return $next($step);
        }

        try {
            $provider = Ai::textProvider(config('packstub-agents.prompt_guard.provider') ?: $step->provider);
            $verdict = GuardAgent::run(Str::limit($question, 4000), $provider, config('packstub-agents.prompt_guard.model') ?: null);
        } catch (Throwable $e) {
            if (! (bool) config('packstub-agents.prompt_guard.fail_open', true)) {
                // The provider itself refused the call (no credits, rate limited, overloaded, unreachable): the turn
                // fails as it would have at the assistant's own step, with that reason, and the turn reports it.
                if ($e instanceof FailoverableException) {
                    throw $e;
                }

                report($e);

                throw new TurnRefused(__('The question could not be checked right now. Try again in a moment.'));
            }

            report($e);

            return $next($step);
        }

        $category = in_array($verdict['category'] ?? null, GuardAgent::CATEGORIES, true) ? $verdict['category'] : GuardAgent::SAFE;

        if ($category === GuardAgent::SAFE) {
            return $next($step);
        }

        $reason = Str::limit(trim((string) ($verdict['reason'] ?? '')), 300);
        $refused = in_array($category, self::refuses(), true);

        Log::channel(config('packstub-agents.log.channel') ?: null)->warning("Agent prompt flagged as {$category}".($refused ? ', refused' : '').'.', [
            'category' => $category,
            'reason' => $reason,
            'refused' => $refused,
            'user' => auth()->id(),
            'tenant' => Agents::tenant()?->getKey(),
            'question' => Str::limit($question, 500),
        ]);

        PromptFlagged::dispatch($category, $reason, $question, $refused);

        if ($refused) {
            throw new TurnRefused(self::refusal($category));
        }

        return $next($step);
    }

    /**
     * The categories that stop a turn: config `prompt_guard.refuse`, as a map (category => bool) or a plain list.
     *
     * @return list<string>
     */
    public static function refuses(): array
    {
        return collect((array) config('packstub-agents.prompt_guard.refuse', []))
            ->map(fn ($on, $category) => is_int($category) ? $on : ($on ? $category : null))
            ->filter(fn ($category) => is_string($category) && $category !== GuardAgent::SAFE && in_array($category, GuardAgent::CATEGORIES, true))
            ->unique()
            ->values()
            ->all();
    }

    /** What the person reads under a question the guard refused. */
    public static function refusal(string $category): string
    {
        return $category === 'off_topic'
            ? __('That is outside what :name helps with here. Ask about your workspace and its records.', ['name' => Agents::name()])
            : __(':name cannot help with that request. Ask about your workspace and its records.', ['name' => Agents::name()]);
    }
}
