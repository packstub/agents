<?php

namespace Packstub\Agents\Support;

/**
 * What a turn cost in money: the token usage priced with config `pricing`
 * (per million tokens, by model name; a key may be a prefix such as
 * "claude-opus-5" for every dated variant). Null when the model has no
 * price — the tokens are still on the row. Prices are yours to keep current;
 * the package ships none, since they change without notice.
 */
class AgentPricing
{
    /**
     * The price list entry for a model: exact name first, then the longest prefix that matches.
     *
     * @return array{in?: float, out?: float, cache_read?: float, cache_write?: float}|null
     */
    public static function for(?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }

        $prices = (array) config('packstub-agents.pricing.models', []);

        if (isset($prices[$model]) && is_array($prices[$model])) {
            return $prices[$model];
        }

        $best = null;

        foreach ($prices as $key => $entry) {
            if (is_array($entry) && str_starts_with($model, (string) $key) && ($best === null || strlen((string) $key) > strlen($best))) {
                $best = (string) $key;
            }
        }

        return $best === null ? null : $prices[$best];
    }

    /**
     * The cost of a usage array (laravel/ai's Usage::toArray) on a model, in the configured currency; null without a price.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function cost(?string $model, ?array $usage): ?float
    {
        $prices = self::for($model);

        if ($prices === null || $usage === null) {
            return null;
        }

        $per = fn (string $key, string $price) => ((float) ($usage[$key] ?? 0)) * ((float) ($prices[$price] ?? 0)) / 1_000_000;

        return round(
            $per('prompt_tokens', 'in')
            + $per('completion_tokens', 'out')
            + $per('reasoning_tokens', 'out')
            + $per('cache_read_input_tokens', 'cache_read')
            + $per('cache_write_input_tokens', 'cache_write'),
            6,
        );
    }

    /** The currency the prices are in ("USD"). */
    public static function currency(): string
    {
        return (string) config('packstub-agents.pricing.currency', 'USD');
    }

    /** A cost as a person reads it: "$0.0123", "€1.20"; the currency code when it has no symbol. */
    public static function format(?float $cost): ?string
    {
        if ($cost === null) {
            return null;
        }

        $symbol = match (self::currency()) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => self::currency().' ',
        };

        return $symbol.number_format($cost, $cost >= 1 ? 2 : 4);
    }
}
