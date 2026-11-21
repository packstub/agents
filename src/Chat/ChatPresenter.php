<?php

namespace Packstub\Agents\Chat;

use Illuminate\Support\Str;
use Stringable;

/** How the chat's views word a tool call: a proposal's preview values, a lookup's arguments and what it found. */
class ChatPresenter
{
    /** A value of a proposal's preview as the person reads it: yes or no, "(empty)" for nothing, a list joined. */
    public static function previewValue(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => __('(empty)'),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => implode(', ', $value),
            is_scalar($value) || $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * A read tool's call on one line of the timeline: its first scalar arguments ("query: acme · status: live").
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function callSummary(array $arguments): ?string
    {
        $parts = [];

        foreach (self::flatten($arguments) as $key => $value) {
            $parts[] = $key.': '.Str::limit((string) $value, 40);

            if (count($parts) === 3) {
                break;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** A read tool's result on the timeline line: how many it found, or what kind of thing it returned. */
    public static function resultSummary(mixed $result): ?string
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;

        if (! is_array($decoded)) {
            return is_string($result) && $result !== '' ? Str::limit($result, 60) : null;
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return __('failed');
        }

        if (isset($decoded['chart'])) {
            return __('a chart');
        }

        if (isset($decoded['table'])) {
            return __('a table');
        }

        if (isset($decoded['total']) && is_numeric($decoded['total'])) {
            return trans_choice('{0} nothing found|{1} :count found|[2,*] :count found', (int) $decoded['total']);
        }

        foreach (['rows', 'items', 'results', 'records', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                return trans_choice('{0} nothing found|{1} :count found|[2,*] :count found', count($decoded[$key]));
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected static function flatten(array $arguments, string $prefix = ''): array
    {
        $flat = [];

        foreach ($arguments as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += array_is_list($value) ? [$name => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value))] : self::flatten($value, $name);
            } elseif (is_scalar($value) && (string) $value !== '') {
                $flat[$name] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            }
        }

        return $flat;
    }
}
