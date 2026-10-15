<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Models\AgentToolCall;
use Packstub\Agents\Models\AgentTurn;

/**
 * Writes agent_tool_calls (config `tool_log`): one row per call, from an
 * agent turn (the chat, AgentRun, a command, the email channel) and from an
 * MCP client alike. RunAgentTurn says which turn is running and what the
 * model asked for, proposed and was told; the turn's tool bridge (TurnTool)
 * says which call is under way; AgentTool::handle() records the checks and
 * the outcome. A row is found again by its call id inside its conversation,
 * and only while it is open, so a provider that repeats an id starts a new
 * row. Arguments and results are redacted (config `redact`) and capped at
 * tool_log.max_bytes. A write that fails is reported and never fails the call.
 */
class AgentToolLog
{
    /** The turn running in this process, if any. */
    protected ?AgentTurn $turn = null;

    /** The provider's id of the call under way, set by the turn's tool bridge. */
    protected ?string $callId = null;

    public static function enabled(): bool
    {
        return (bool) config('packstub-agents.tool_log.enabled', true);
    }

    /** The calls that follow belong to this turn; null when it ends. */
    public function during(?AgentTurn $turn): void
    {
        $this->turn = $turn;
    }

    /**
     * Run a call of an agent turn under its provider id, so AgentTool::handle() finds the row the model's call made.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public function within(?string $callId, Closure $run): mixed
    {
        $previous = $this->callId;
        $this->callId = $callId ?? (string) Str::uuid7();

        try {
            return $run();
        } finally {
            $this->callId = $previous;
        }
    }

    /** The model asked for a call (the stream's ToolCall): on record before anything runs. */
    public function called(AgentTurn $turn, ToolCall $call): void
    {
        $this->write(function () use ($turn, $call) {
            $this->open($turn->conversation_id, $call->id) ?? $this->create($call->id, $call->name, $call->arguments, AgentToolCall::CALLED, $turn);
        });
    }

    /** A write tool's call waits for the person's decision, as this question. */
    public function proposed(AgentTurn $turn, PendingApproval $approval): void
    {
        $this->write(function () use ($turn, $approval) {
            $row = $this->open($turn->conversation_id, $approval->id) ?? $this->create($approval->id, $approval->tool, $approval->arguments, AgentToolCall::PROPOSED, $turn);
            $row->update(['status' => AgentToolCall::PROPOSED, 'question' => $approval->reason]);
        });
    }

    /**
     * The person decided a proposal: approved (it runs next), rejected, or superseded by a question typed instead.
     *
     * @param  array<string, mixed>  $arguments  the call as it was proposed
     */
    public function decided(AgentTurn $turn, string $callId, string $tool, array $arguments, string $decision): void
    {
        $this->write(function () use ($turn, $callId, $tool, $arguments, $decision) {
            $row = $this->open($turn->conversation_id, $callId) ?? $this->create($callId, $tool, $arguments, AgentToolCall::PROPOSED, $turn);

            $approved = $decision === 'approved';

            $row->update([
                'status' => $approved ? AgentToolCall::APPROVED : AgentToolCall::REJECTED,
                'decision' => $decision,
                'decided_by' => $turn->decidedBy(),
                'decided_at' => now(),
                'finished_at' => $approved ? null : now(),
            ]);
        });
    }

    /**
     * The call reached the tool (AgentTool::handle()): the row it continues, or a new one for an MCP or direct call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function start(AgentTool $tool, ?string $ability, array $arguments): ?AgentToolCall
    {
        return $this->write(function () use ($tool, $ability, $arguments) {
            $row = $this->callId !== null ? $this->open($this->turn?->conversation_id, $this->callId) : null;
            $row ??= $this->create($this->callId ?? (string) Str::uuid7(), $tool->name(), $arguments, AgentToolCall::RUNNING, $this->turn);

            $token = AgentTool::accessToken();
            $row->update(['status' => AgentToolCall::RUNNING, 'ability' => $ability, 'token_id' => $token instanceof Model ? $token->getKey() : null, 'started_at' => now()]);

            return $row;
        });
    }

    /** The workspace, the role or the token refused the call before it ran. */
    public function refused(?AgentToolCall $row, string $by, string $message): void
    {
        $this->finish($row, ['status' => AgentToolCall::REFUSED, 'refused_by' => $by, 'error' => $message]);
    }

    /**
     * The call ran: the result the model read.
     *
     * @param  array<string, mixed>  $result
     */
    public function succeeded(?AgentToolCall $row, array $result, float $startedAt): void
    {
        $this->finish($row, [
            'status' => AgentToolCall::SUCCEEDED,
            'result' => config('packstub-agents.tool_log.results', true) ? $this->capped(app(AgentRedactor::class)->redactResult($result)) : null,
            'duration_ms' => self::since($startedAt),
        ]);
    }

    /** The call ran and failed: the error the model read. */
    public function failed(?AgentToolCall $row, string $error, float $startedAt): void
    {
        $this->finish($row, ['status' => AgentToolCall::FAILED, 'error' => app(AgentRedactor::class)->redact($error), 'duration_ms' => self::since($startedAt)]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function finish(?AgentToolCall $row, array $attributes): void
    {
        if ($row === null) {
            return;
        }

        $this->write(fn () => $row->isOpen() ? $row->update($attributes + ['finished_at' => now()]) : null);
    }

    /** The open row of a call in a conversation (null for a call outside one), the latest when an id repeats. */
    protected function open(?string $conversationId, string $callId): ?AgentToolCall
    {
        return AgentToolCall::query()
            ->where('call_id', $callId)
            ->where('conversation_id', $conversationId)
            ->whereIn('status', AgentToolCall::OPEN)
            ->latest('id')
            ->first();
    }

    /** @param  array<string, mixed>  $arguments */
    protected function create(string $callId, string $tool, array $arguments, string $status, ?AgentTurn $turn): AgentToolCall
    {
        $context = Agents::context();
        $where = $context->capture();
        $user = $context->user();

        return AgentToolCall::query()->create([
            'call_id' => $callId,
            'channel' => match (true) {
                $turn !== null || $this->callId !== null => 'agent',
                app()->bound('mcp.request') => 'mcp',
                default => 'direct',
            },
            'turn_id' => $turn?->id,
            'conversation_id' => $turn?->conversation_id,
            'participant_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'participant_id' => $user?->getAuthIdentifier(),
            'tenant' => $where['tenant'] !== null ? (string) $where['tenant'] : $turn?->tenant,
            'panel' => $where['panel'] ?? $turn?->panel,
            'tool' => $tool,
            'arguments' => $this->capped(app(AgentRedactor::class)->redactResult($arguments)),
            'status' => $status,
        ]);
    }

    /** A value larger than tool_log.max_bytes as JSON is kept as its first bytes, marked as cut. */
    protected function capped(mixed $value): mixed
    {
        $max = (int) config('packstub-agents.tool_log.max_bytes', 65536);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($max <= 0 || $json === false || strlen($json) <= $max) {
            return $value;
        }

        return ['truncated' => true, 'bytes' => strlen($json), 'excerpt' => mb_strcut($json, 0, $max)];
    }

    protected static function since(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * A write of its own: in a transaction (a savepoint inside the caller's), so a failed one leaves the caller's
     * transaction usable — Postgres refuses every statement after an error until it is rolled back — and reported.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T|null
     */
    protected function write(Closure $write): mixed
    {
        if (! self::enabled()) {
            return null;
        }

        return rescue(fn () => (new AgentToolCall)->getConnection()->transaction($write), null, report: true);
    }
}
