<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Facades\Log;
use Packstub\Agents\Events\OutputRedacted;
use Packstub\Agents\Facades\Agents;
use Throwable;

/**
 * Keeps secrets and personal data out of what the assistant shows and what
 * the chat stores (config `redact`, off by default): card numbers, US social
 * security numbers and API keys by pattern, plus the app's own patterns and
 * a callback (Agents::redactUsing()). It runs on the answer as it streams —
 * every snapshot the page reads, so a value is never shown and then taken
 * back — on the stored answer, and on the tool results kept with it, which
 * the model reads again as history.
 *
 * One instance lives per container (a request, a queued job's turn): it
 * remembers which kinds it replaced, and report() writes one `critical` log
 * line and fires OutputRedacted for them.
 */
class AgentRedactor
{
    /** @var array<string, true> the kinds replaced since the last report */
    protected array $kinds = [];

    /** While a turn collects, report() waits for the turn's own call (RunAgentTurn), so a turn logs once. */
    protected bool $collecting = false;

    public static function enabled(): bool
    {
        return (bool) config('packstub-agents.redact.enabled', false);
    }

    public static function replacement(): string
    {
        return (string) config('packstub-agents.redact.replacement', '[redacted]');
    }

    /** The text with every detected value replaced; untouched when redaction is off. */
    public function redact(string $text): string
    {
        return self::enabled() && $text !== '' ? $this->apply($text, true) : $text;
    }

    /**
     * The answer so far, as a surface may show it while it streams: redacted, and without a trailing piece that
     * could be the start of a value still being written — a run of digits, or a word that has not ended — so a
     * card number or a key is held back until it is complete and then shown replaced. Patterns of the app's own
     * that span several words cannot be held back this way; they are replaced once they are whole.
     */
    public function streaming(string $buffer): string
    {
        if (! self::enabled() || $buffer === '') {
            return $buffer;
        }

        $held = preg_replace('/(?:(?<!\d)\d[\d \-]*|\S+)$/D', '', $buffer) ?? '';

        return $this->apply($held, false);
    }

    /**
     * A tool result as it is stored: a JSON result is redacted value by value, so it stays JSON (a chart or a
     * table is still read from it); anything else as text.
     */
    public function redactResult(mixed $result): mixed
    {
        if (! self::enabled()) {
            return $result;
        }

        if (is_array($result)) {
            return $this->walk($result);
        }

        if (! is_string($result) || $result === '') {
            return $result;
        }

        $decoded = json_decode($result, true);

        if (is_array($decoded)) {
            $redacted = $this->walk($decoded);

            return $redacted === $decoded ? $result : json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->apply($result, true);
    }

    /**
     * The kinds replaced since the last report ("card", "ssn", "api_key", a pattern's label, "custom").
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_keys($this->kinds);
    }

    /** A turn starts collecting: what the store redacts on its behalf is reported once, by the turn. */
    public function collecting(bool $collecting = true): static
    {
        $this->collecting = $collecting;

        return $this;
    }

    /**
     * Say that something was redacted — one `critical` log line and an OutputRedacted event with the kinds, never
     * the values — and forget them. Nothing when nothing was, or while a turn collects (unless it is the turn's call).
     *
     * @param  array<string, mixed>  $context  the turn, the conversation…
     */
    public function report(array $context = [], bool $force = false): void
    {
        if ($this->kinds === [] || ($this->collecting && ! $force)) {
            return;
        }

        $kinds = $this->kinds();
        $this->kinds = [];

        $context += ['user' => auth()->id(), 'tenant' => Agents::tenant()?->getKey()];

        Log::critical('Agent output redacted: '.implode(', ', $kinds).'.', ['kinds' => $kinds] + $context);

        OutputRedacted::dispatch($kinds, $context);
    }

    /** @param  array<array-key, mixed>  $value */
    protected function walk(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->walk($item);
            } elseif (is_string($item)) {
                $value[$key] = $this->apply($item, true);
            } elseif (is_int($item) && $item > 999_999_999_999) {
                // A long number (a card stored as an integer) is redacted as text; shorter ones cannot be a card.
                $redacted = $this->apply((string) $item, true);
                $value[$key] = $redacted === (string) $item ? $item : $redacted;
            }
        }

        return $value;
    }

    protected function apply(string $text, bool $count): string
    {
        $replacement = self::replacement();

        foreach ($this->detectors() as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                $text = preg_replace_callback($pattern, function (array $match) use ($kind, $replacement, $count): string {
                    if ($kind === 'card' && ! self::luhn($match[0])) {
                        return $match[0];
                    }

                    if ($count) {
                        $this->kinds[$kind] = true;
                    }

                    return $replacement;
                }, $text) ?? $text;
            }
        }

        if ($callback = Agents::redactor()) {
            try {
                $custom = (string) $callback($text);
            } catch (Throwable $e) {
                report($e);
                $custom = $text;
            }

            if ($custom !== $text) {
                if ($count) {
                    $this->kinds['custom'] = true;
                }

                $text = $custom;
            }
        }

        return $text;
    }

    /**
     * The patterns in use, by kind: the built-in ones named in `redact.detect`, then the app's (`redact.patterns`,
     * label => regex; a pattern that does not compile is skipped).
     *
     * @return array<string, list<string>>
     */
    protected function detectors(): array
    {
        // Keys first: a long key may hold a run of digits that reads as a card number, and must go whole.
        $builtIn = [
            'api_key' => [
                '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----/',
                '/\b(?:sk|pk|rk)[-_](?:live|test|proj|ant|or)[-_][A-Za-z0-9_\-]{16,}/', // Stripe, OpenAI project keys, Anthropic, OpenRouter
                '/\bsk-[A-Za-z0-9_\-]{20,}/', // OpenAI and look-alikes
                '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', // AWS access key ids
                '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/', // GitHub tokens
                '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/',
                '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/', // Slack tokens
                '/\bAIza[0-9A-Za-z_\-]{35}\b/', // Google API keys
                '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/', // JSON web tokens
                '/(?<![\w|])\d{1,10}\|[A-Za-z0-9]{40,}\b/', // Laravel Sanctum tokens, the agent access tokens among them
                '/\bBearer\s+[A-Za-z0-9._~+\/\-]{20,}=*/i',
            ],
            // 13 to 19 digits, in groups or not, that pass the Luhn check (an order number or a phone number rarely does).
            'card' => ['/(?<![\w.])\d(?:[ \-]?\d){12,18}(?!\w)/'],
            // A US social security number as it is written; the ranges that are never issued are left alone.
            'ssn' => ['/(?<![\w\-])(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}(?![\w\-])/'],
        ];

        // `redact.detect` is a map (kind => bool) or a plain list of kinds.
        $wanted = collect((array) config('packstub-agents.redact.detect', array_keys($builtIn)))
            ->map(fn ($on, $kind) => is_int($kind) ? $on : ($on ? $kind : null))
            ->filter(fn ($kind) => is_string($kind))
            ->all();

        $detectors = array_intersect_key($builtIn, array_flip($wanted));

        foreach ((array) config('packstub-agents.redact.patterns', []) as $label => $pattern) {
            if (is_string($pattern) && @preg_match($pattern, '') !== false) {
                $detectors[is_string($label) ? $label : 'pattern'][] = $pattern;
            }
        }

        return $detectors;
    }

    /** The Luhn check every payment card number passes. */
    public static function luhn(string $number): bool
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1 && ($digit *= 2) > 9) {
                $digit -= 9;
            }

            $sum += $digit;
        }

        return $sum % 10 === 0 && trim($digits, '0') !== '';
    }
}
