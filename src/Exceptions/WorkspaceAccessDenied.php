<?php

namespace Packstub\Agents\Exceptions;

use RuntimeException;

/**
 * A workspace was about to be entered for a person who is not a member of it
 * (canAccessTenant() said no), or for nobody at all without `system => true`.
 * Thrown by the context's enter() on every path
 * — AgentRun, the email channel, the queued turn — so nothing runs inside the
 * workspace; the message is what the person reads.
 */
class WorkspaceAccessDenied extends RuntimeException
{
    public static function make(): static
    {
        return new static(__('You are not a member of this workspace.'));
    }
}
