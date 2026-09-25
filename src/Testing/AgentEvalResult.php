<?php

namespace Packstub\Agents\Testing;

use Illuminate\Support\Collection;
use Packstub\Agents\Support\AgentAnswer;
use Packstub\Agents\Support\AgentChat;
use PHPUnit\Framework\Assert;

/**
 * What one ask() of an AgentEval produced, with assertions on it. The tool
 * calls are read from the stored answer, so they are what the model sent
 * and what the store kept — not what the fake was told.
 */
class AgentEvalResult
{
    public function __construct(public readonly AgentAnswer $answer, public readonly AgentChat $chat, protected AgentEval $eval) {}

    public function text(): string
    {
        return $this->answer->text;
    }

    /**
     * Every tool call of the answer to the last question, in order: name, arguments, whether it was a proposal
     * (pending / approved / rejected) and its result.
     *
     * @return Collection<int, array{id: ?string, name: string, arguments: array<string, mixed>, readOnly: bool, pending: bool, rejected: bool, result: mixed}>
     */
    public function toolCalls(): Collection
    {
        $messages = $this->chat->messages();
        $last = null;

        foreach ($messages as $i => $m) {
            if ($m['role'] === 'user') {
                $last = $i;
            }
        }

        return $messages->slice($last === null ? 0 : $last + 1)
            ->filter(fn (array $m) => $m['role'] === 'assistant')
            ->flatMap(fn (array $m) => $m['tools'])
            ->map(fn (array $t) => ['id' => $t['id'], 'name' => $t['tool'], 'arguments' => $t['arguments'], 'readOnly' => $t['readOnly'], 'pending' => $t['pending'], 'rejected' => $t['rejected'], 'result' => $t['result']])
            ->values();
    }

    /** The proposals left waiting for a decision. */
    public function proposals(): Collection
    {
        return $this->answer->proposals;
    }

    /** Continue with the next question on the same eval (and conversation). */
    public function then(): AgentEval
    {
        return $this->eval;
    }

    public function assertOk(): static
    {
        Assert::assertTrue($this->answer->ok(), 'The turn did not end with an answer: '.($this->answer->error() ?? $this->answer->turn?->status ?? 'no turn'));

        return $this;
    }

    public function assertFailed(?string $error = null): static
    {
        Assert::assertTrue($this->answer->failed(), 'The turn did not fail; it answered: '.$this->answer->text);

        if ($error !== null) {
            Assert::assertStringContainsString($error, (string) $this->answer->error());
        }

        return $this;
    }

    public function assertRefused(?string $reason = null): static
    {
        Assert::assertTrue($this->answer->refused(), 'The turn was not refused by a middleware; it ended '.($this->answer->turn?->finish_reason ?? 'without a turn').'.');

        if ($reason !== null) {
            Assert::assertStringContainsString($reason, (string) $this->answer->error());
        }

        return $this;
    }

    public function assertAnswerContains(string $text): static
    {
        Assert::assertStringContainsStringIgnoringCase($text, $this->answer->text, 'The answer does not contain "'.$text.'": '.$this->answer->text);

        return $this;
    }

    public function assertAnswerNotContains(string $text): static
    {
        Assert::assertStringNotContainsStringIgnoringCase($text, $this->answer->text, 'The answer contains "'.$text.'": '.$this->answer->text);

        return $this;
    }

    /**
     * The tool was called; with $arguments, at least one call carried every given argument with that value (a
     * subset match, so an extra argument the model added does not fail the eval).
     *
     * @param  array<string, mixed>|null  $arguments
     */
    public function assertCalled(string $tool, ?array $arguments = null): static
    {
        $calls = $this->toolCalls()->where('name', $tool);

        Assert::assertTrue($calls->isNotEmpty(), "The agent did not call {$tool}; it called: ".($this->called() ?: 'nothing').'.');

        if ($arguments !== null) {
            $match = $calls->first(fn (array $call) => self::subset($arguments, $call['arguments']));
            Assert::assertNotNull($match, "No call of {$tool} carried ".json_encode($arguments, JSON_UNESCAPED_UNICODE).'; the calls were: '.json_encode($calls->pluck('arguments')->all(), JSON_UNESCAPED_UNICODE).'.');
        }

        return $this;
    }

    public function assertNotCalled(string $tool): static
    {
        Assert::assertTrue($this->toolCalls()->where('name', $tool)->isEmpty(), "The agent called {$tool}.");

        return $this;
    }

    /** The tools were called in this order (other calls may sit between them). */
    public function assertCalledInOrder(array $tools): static
    {
        $names = $this->toolCalls()->pluck('name')->all();
        $at = 0;

        foreach ($tools as $tool) {
            $found = false;

            for (; $at < count($names); $at++) {
                if ($names[$at] === $tool) {
                    $found = true;
                    $at++;

                    break;
                }
            }

            Assert::assertTrue($found, "The agent did not call {$tool} in that order; it called: ".($this->called() ?: 'nothing').'.');
        }

        return $this;
    }

    /** A write tool was proposed (and waits for a decision, unless it was decided). */
    public function assertProposed(string $tool, ?array $arguments = null): static
    {
        $this->assertCalled($tool, $arguments);
        Assert::assertTrue($this->toolCalls()->where('name', $tool)->contains(fn (array $c) => ! $c['readOnly']), "{$tool} ran as a read tool; it was not a proposal.");

        return $this;
    }

    public function assertNothingProposed(): static
    {
        Assert::assertTrue($this->proposals()->isEmpty(), 'A proposal waits for a decision: '.$this->proposals()->pluck('question')->join(', '));

        return $this;
    }

    public function assertNoToolCalls(): static
    {
        Assert::assertTrue($this->toolCalls()->isEmpty(), 'The agent called: '.$this->called().'.');

        return $this;
    }

    /** The recorded turn's tools (AgentTurn::tool_calls), for a check on the log rather than the transcript. */
    public function assertTurnTools(array $tools): static
    {
        Assert::assertSame($tools, $this->answer->tools());

        return $this;
    }

    protected function called(): string
    {
        return $this->toolCalls()->pluck('name')->join(', ');
    }

    protected static function subset(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value) && is_array($actual[$key]) ? ! self::subset($value, $actual[$key]) && $value != $actual[$key] : $actual[$key] != $value) {
                return false;
            }
        }

        return true;
    }
}
