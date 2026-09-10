<?php

namespace Packstub\Agents\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Packstub\Agents\Mcp\AgentTool;

/**
 * A write tool as the chat sees it: the same laravel/mcp tool, but the agent
 * may only PROPOSE the call. laravel/ai pauses the turn, the panel shows what
 * would run as a question ("Confirm order RO-00016?") with Approve / Reject,
 * and only an approval executes it — the human stays in the loop for every
 * change. The question is the approval's reason, so any client reading the
 * pending approvals gets the same sentence.
 */
class ApprovableTool extends McpServerTool implements Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required(self::question($this->tool, $request->all()));
    }

    /**
     * The call as a question: the tool's own AgentTool::describe() when it has one,
     * otherwise its title and the first scalar argument ("Retire Widget 12?").
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function question(object $tool, array $arguments): string
    {
        if ($tool instanceof AgentTool && ($question = $tool->describe($arguments)) !== null) {
            return $question;
        }

        $title = method_exists($tool, 'title') ? $tool->title() : Str::headline(class_basename($tool));
        $first = collect($arguments)->first(fn ($value) => is_scalar($value) && $value !== '');

        return rtrim($title.($first === null ? '' : ' '.(is_bool($first) ? var_export($first, true) : $first)), '?').'?';
    }
}
