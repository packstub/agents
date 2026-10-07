<?php

namespace Packstub\Agents\Support;

use Closure;
use Packstub\Agents\Facades\Agents;

/**
 * Puts a queue worker into the shape of the request a turn was sent from,
 * through the AgentContext in use: in a panel the panel is made current, the
 * workspace is set (which fires TenantSet, so a tenancy plugin switches the
 * database as it would for a page), the person is signed in on the panel's
 * guard and the locale is applied; without one the same on the default guard
 * and the app's own workspace hook. Every tool, ability check and prompt line
 * then behaves exactly as it does in the chat. enter() returns a closure that
 * restores what was there before — a no-op inside a request, a clean-up on a
 * long-lived worker.
 */
class AgentRuntime
{
    /**
     * @return array{panel: ?string, tenant: int|string|null, user: int|string|null, locale: string, guard: string}
     */
    public static function capture(): array
    {
        return Agents::context()->capture();
    }

    /**
     * A `tenant` needs a member to act: the `user` given, else the one signed in. Nobody at all is refused with
     * WorkspaceAccessDenied — a job that acts for the app itself, not for a person, passes `system => true`.
     *
     * @param  array{panel?: ?string, tenant?: int|string|null, user?: int|string|null, locale?: ?string, guard?: ?string, system?: bool}  $context
     */
    public static function enter(array $context): Closure
    {
        return Agents::context()->enter($context);
    }
}
