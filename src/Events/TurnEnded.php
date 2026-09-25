<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Agents\Models\AgentTurn;

/**
 * A turn ended — done, stopped or failed — and its row carries the record:
 * provider and model, usage, cost, tools called, duration, finish reason.
 * An audit log, a metrics sink or a notification hangs off this.
 */
class TurnEnded
{
    use Dispatchable;

    public function __construct(public AgentTurn $turn) {}
}
