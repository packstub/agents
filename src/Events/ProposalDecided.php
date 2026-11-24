<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Agents\Models\AgentTurn;

/**
 * The person approved or rejected a proposed change, and the decision turn is
 * about to apply it: $tool and $arguments are the call as it was proposed.
 * $approvedArguments are what it runs with when the person corrected the
 * arguments on the approval card before approving (null: as proposed).
 *
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>|null  $approvedArguments
 */
class ProposalDecided
{
    use Dispatchable;

    public function __construct(public AgentTurn $turn, public string $callId, public string $tool, public array $arguments, public bool $approved, public ?array $approvedArguments = null) {}
}
