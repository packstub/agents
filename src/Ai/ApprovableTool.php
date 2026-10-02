<?php

namespace Packstub\Agents\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Packstub\Agents\Mcp\AgentTool;
use Throwable;

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

        return self::phrase(method_exists($tool, 'title') ? $tool->title() : Str::headline(class_basename($tool)), $arguments);
    }

    /**
     * What the call would change (AgentTool::preview()), each row with a label and a before and/or an after; empty
     * for a tool without a preview, one that is no longer registered, and one whose preview throws (reported, so a
     * broken preview never stands between the person and the decision).
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array{label: string, before?: mixed, after?: mixed}>
     */
    public static function preview(?object $tool, array $arguments): array
    {
        if (! $tool instanceof AgentTool) {
            return [];
        }

        try {
            return array_values(array_filter(
                $tool->preview($arguments),
                fn ($row) => is_array($row) && is_string($row['label'] ?? null) && (array_key_exists('before', $row) || array_key_exists('after', $row)),
            ));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * A title and the first scalar argument as a question ("Retire Widget 12?", "Archive Widget true?"): the one
     * phrasing for a tool without a sentence of its own and for a call whose tool is no longer registered.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function phrase(string $title, array $arguments): string
    {
        $first = collect($arguments)->first(fn ($value) => is_scalar($value) && $value !== '');

        return rtrim($title.($first === null ? '' : ' '.(is_bool($first) ? var_export($first, true) : $first)), '?').'?';
    }
}
