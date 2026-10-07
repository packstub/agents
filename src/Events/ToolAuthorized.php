<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Agents\Mcp\AgentTool;

/**
 * A tool was called and the call was allowed or refused, before it runs:
 * from the chat and from an MCP client alike, so a listener sees every
 * decision, the refusals included (the model only gets an error for those).
 * $refusedBy says which check refused it: 'workspace' (the person is no
 * longer a member of the workspace the call runs in), 'role' (the tool's
 * $ability) or 'token' (a read-only or tool-scoped access token). Not fired
 * for the tool list, which checks the same abilities on every listing.
 *
 * @param  array<string, mixed>  $arguments
 */
class ToolAuthorized
{
    use Dispatchable;

    public function __construct(
        public AgentTool $tool,
        public ?string $ability,
        public array $arguments,
        public bool $allowed,
        public ?string $refusal = null,
        public ?string $refusedBy = null,
    ) {}
}
