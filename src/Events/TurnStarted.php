<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Agents\Models\AgentTurn;

/** The job took a turn and is about to call the provider. */
class TurnStarted
{
    use Dispatchable;

    public function __construct(public AgentTurn $turn) {}
}
