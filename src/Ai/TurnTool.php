<?php

namespace Packstub\Agents\Ai;

use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Packstub\Agents\Support\AgentToolLog;

/**
 * A tool as an agent turn runs it: laravel/ai's bridge to the laravel/mcp
 * tool, run under the provider's id of the call, so the call's row in the
 * tool log (AgentToolLog) is the one the model's call and its approval wrote.
 */
class TurnTool extends McpServerTool
{
    public function handle(Request $request): string
    {
        return app(AgentToolLog::class)->within($request->toolCallId(), fn (): string => parent::handle($request));
    }
}
