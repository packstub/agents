<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Agents\Models\AgentTurn;

/**
 * The model called a tool during a turn (a read tool runs at once; a write
 * tool becomes a proposal). Fired with the arguments as the model sent them.
 *
 * @param  array<string, mixed>  $arguments
 */
class ToolCalled
{
    use Dispatchable;

    public function __construct(public AgentTurn $turn, public string $callId, public string $tool, public array $arguments) {}
}
