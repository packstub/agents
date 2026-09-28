<?php

namespace Packstub\Agents\Support;

/**
 * A stored usage array read either way it was written. laravel/ai 1.0 reports
 * inclusive counts — `input_tokens` holds the cached tokens too and
 * `output_tokens` the reasoning — where 0.x wrote `prompt_tokens` and
 * `completion_tokens` beside them. Rows written before the upgrade keep the
 * old keys, so every reader goes through here.
 */
final class AgentUsage
{
    /** Whether the array was written by laravel/ai 1.0 (inclusive counts). */
    public static function isInclusive(array $usage): bool
    {
        return array_key_exists('input_tokens', $usage) || array_key_exists('output_tokens', $usage);
    }

    /** Tokens the provider read: the prompt, cached or not. */
    public static function in(?array $usage): ?int
    {
        if ($usage === null) {
            return null;
        }

        return self::isInclusive($usage)
            ? (int) ($usage['input_tokens'] ?? 0)
            : (int) ($usage['prompt_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_write_input_tokens'] ?? 0);
    }

    /** Tokens the provider wrote: the answer and the reasoning. */
    public static function out(?array $usage): ?int
    {
        if ($usage === null) {
            return null;
        }

        return self::isInclusive($usage)
            ? (int) ($usage['output_tokens'] ?? 0)
            : (int) ($usage['completion_tokens'] ?? 0) + (int) ($usage['reasoning_tokens'] ?? 0);
    }

    /** Every token the provider counted, in and out. */
    public static function total(?array $usage): int
    {
        return (int) self::in($usage) + (int) self::out($usage);
    }

    /**
     * The counts a price applies to: the uncached input, the cache reads and writes, the output (reasoning included).
     *
     * @return array{uncached_in: int, cache_read: int, cache_write: int, out: int}
     */
    public static function priced(array $usage): array
    {
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_write_input_tokens'] ?? 0);

        return [
            'uncached_in' => max(0, self::isInclusive($usage) ? (int) ($usage['input_tokens'] ?? 0) - $cacheRead - $cacheWrite : (int) ($usage['prompt_tokens'] ?? 0)),
            'cache_read' => $cacheRead,
            'cache_write' => $cacheWrite,
            'out' => (int) self::out($usage),
        ];
    }
}
