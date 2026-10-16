<?php

namespace Packstub\Agents\Ai;

use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Support\AgentToolLog;
use Throwable;

/**
 * A tool as an agent turn runs it: laravel/ai's bridge to the laravel/mcp
 * tool, run under the provider's id of the call, so the call's row in the
 * tool log (AgentToolLog) is the one the model's call and its approval wrote.
 * An AgentTool records its checks and outcome itself; any other laravel/mcp
 * tool has them recorded here, so its row does not stay open.
 */
class TurnTool extends McpServerTool
{
    public function handle(Request $request): string
    {
        $log = app(AgentToolLog::class);

        return $log->within($request->toolCallId(), function () use ($log, $request): string {
            if ($this->tool instanceof AgentTool) {
                return parent::handle($request);
            }

            $call = $log->start($this->tool->name(), null, $request->toArray());
            $startedAt = microtime(true);

            try {
                $output = parent::handle($request);
            } catch (Throwable $e) {
                $log->failed($call, $e->getMessage(), $startedAt);

                throw $e;
            }

            $result = json_decode($output, true);
            $log->succeeded($call, is_array($result) ? $result : ['text' => $output], $startedAt);

            return $output;
        });
    }
}
