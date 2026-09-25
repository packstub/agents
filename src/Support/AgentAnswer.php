<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Collection;
use Packstub\Agents\Models\AgentTurn;

/**
 * What a headless run of the assistant came back with (AgentRun, the email
 * channel, the run command): the answer's text and HTML, the turn with its
 * record, the conversation it landed in, and the proposals it left waiting
 * for a decision (a write tool a headless run cannot approve).
 */
final class AgentAnswer
{
    /**
     * @param  Collection<int, array<string, mixed>>  $proposals  the pending write calls (question, tool, arguments)
     */
    public function __construct(
        public readonly ?AgentTurn $turn,
        public readonly ?string $conversation,
        public readonly string $text,
        public readonly Collection $proposals,
    ) {}

    /** The turn ended with an answer (done, or stopped with text). */
    public function ok(): bool
    {
        return $this->turn !== null && in_array($this->turn->status, [AgentTurn::DONE, AgentTurn::STOPPED], true);
    }

    public function failed(): bool
    {
        return $this->turn === null || $this->turn->status === AgentTurn::FAILED;
    }

    /** A middleware refused the turn (a budget spent, a guard). */
    public function refused(): bool
    {
        return $this->turn?->finish_reason === 'refused';
    }

    public function error(): ?string
    {
        return $this->turn?->error;
    }

    public function html(): string
    {
        return Markdown::render($this->text);
    }

    /** The tools the turn called, in order. @return list<string> */
    public function tools(): array
    {
        return array_values((array) ($this->turn?->tool_calls ?? []));
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
