<?php

namespace Packstub\Agents\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentTurn;

/**
 * Keeps the bill bounded: a burst limit per user, a daily number of turns per
 * workspace, a daily + monthly token budget per workspace and per user, all
 * counted from the record every turn leaves on agent_turns — which carries
 * the workspace, so on a shared database one workspace's turns never count
 * against another's. Checked before a turn reaches the provider; the
 * provider's own spend limit stays the backstop.
 */
class AgentBudget
{
    /** The reason a turn may not start, or null when it may. */
    public static function refusal(?string $prompt = null): ?string
    {
        $limits = AgentLimits::effective();

        if (! $limits['enabled']) {
            return __(':name is switched off for this workspace.', ['name' => Agents::name()]);
        }

        if ($prompt !== null && ($max = $limits['prompt_max_chars'] ?? null) && mb_strlen($prompt) > $max) {
            return __('That question is too long (max :n characters).', ['n' => $max]);
        }

        if (($perMinute = $limits['turns_per_minute'] ?? null) && RateLimiter::tooManyAttempts(self::minuteKey(), $perMinute)) {
            return __('Too many questions in a row — give it a minute.');
        }

        if (($perDay = $limits['turns_per_day'] ?? null) && self::turnsToday() >= $perDay) {
            return __('This workspace reached today\'s limit of :n answers. It resets at midnight.', ['n' => $perDay]);
        }

        if (($perDay = $limits['tokens_per_day'] ?? null) && self::tokensToday() >= $perDay) {
            return __('This workspace used its AI budget for today. It resets at midnight.');
        }

        if (($perMonth = $limits['tokens_per_month'] ?? null) && self::tokensThisMonth() >= $perMonth) {
            return __('This workspace used its AI budget for the month.');
        }

        if (($perUserDay = $limits['user_tokens_per_day'] ?? null) && self::tokensToday(auth()->id()) >= $perUserDay) {
            return __('You used your AI budget for today. It resets at midnight.');
        }

        if (($perUser = $limits['user_tokens_per_month'] ?? null) && self::tokensThisMonth(auth()->id()) >= $perUser) {
            return __('You used your AI budget for the month.');
        }

        return null;
    }

    /** Count the turn that is about to run against the per-minute limit. */
    public static function hit(): void
    {
        RateLimiter::hit(self::minuteKey(), 60);
    }

    /** The turns the provider answered today in this workspace (done, or stopped by the person while it answered). */
    public static function turnsToday(): int
    {
        return self::endedSince(now()->startOfDay())->whereIn('status', [AgentTurn::DONE, AgentTurn::STOPPED])->count();
    }

    public static function tokensToday(int|string|null $userId = null): int
    {
        return self::tokensSince(now()->startOfDay(), $userId);
    }

    public static function tokensThisMonth(int|string|null $userId = null): int
    {
        return self::tokensSince(now()->startOfMonth(), $userId);
    }

    /** All token kinds the provider reported, for the workspace or for one of its users; a turn that failed half-way counts what it used. */
    protected static function tokensSince(CarbonInterface $since, int|string|null $userId = null): int
    {
        return (int) self::endedSince($since)
            ->when($userId, fn ($q) => $q->where('participant_id', $userId))
            ->pluck('usage')
            ->sum(fn ($usage) => AgentUsage::total(is_array($usage) ? $usage : null));
    }

    /**
     * The turns that ended since the moment, in the current workspace: the one
     * entered or resolved, else the rows without one — every row, in an app
     * without workspaces.
     *
     * @return Builder<AgentTurn>
     */
    protected static function endedSince(CarbonInterface $since): Builder
    {
        $tenant = Agents::tenant()?->getKey();

        return AgentTurn::query()
            ->when($tenant !== null, fn ($q) => $q->where('tenant', (string) $tenant), fn ($q) => $q->whereNull('tenant'))
            ->where('finished_at', '>=', $since);
    }

    /**
     * @return array{turns_today: int, turns_per_day: ?int, tokens_today: int, tokens_per_day: ?int, tokens_month: int, tokens_per_month: ?int,
     *               user_tokens_today: int, user_tokens_per_day: ?int, user_tokens_month: int, user_tokens_per_month: ?int}
     */
    public static function summary(): array
    {
        $limits = AgentLimits::effective();

        return [
            'turns_today' => self::turnsToday(),
            'turns_per_day' => $limits['turns_per_day'],
            'tokens_today' => self::tokensToday(),
            'tokens_per_day' => $limits['tokens_per_day'],
            'tokens_month' => self::tokensThisMonth(),
            'tokens_per_month' => $limits['tokens_per_month'],
            'user_tokens_today' => self::tokensToday(auth()->id()),
            'user_tokens_per_day' => $limits['user_tokens_per_day'],
            'user_tokens_month' => self::tokensThisMonth(auth()->id()),
            'user_tokens_per_month' => $limits['user_tokens_per_month'],
        ];
    }

    protected static function minuteKey(): string
    {
        return 'agent-turns:'.(Agents::tenant()?->getKey() ?? 'central').':'.auth()->id();
    }
}
