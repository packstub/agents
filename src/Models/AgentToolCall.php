<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One tool call on record (config `tool_log`): who ran it, where, with which
 * arguments, what the person decided when it was a proposed write, and how
 * it ended with the result the model read. AgentToolLog writes it as the
 * call moves on — called, proposed, approved, running — and leaves it alone
 * once it has ended (succeeded, failed, refused, rejected). A row outlives
 * its conversation and is pruned after tool_log.keep_days.
 */
class AgentToolCall extends Model
{
    use MassPrunable;

    /** The model asked for the call; nothing ran yet. */
    public const string CALLED = 'called';

    /** A write waiting for the person's decision in the chat. */
    public const string PROPOSED = 'proposed';

    /** The person approved it; it runs next. */
    public const string APPROVED = 'approved';

    public const string RUNNING = 'running';

    public const string SUCCEEDED = 'succeeded';

    /** It ran and failed: invalid arguments, a domain error, an exception. */
    public const string FAILED = 'failed';

    /** The workspace, the role or the token refused it before it ran. */
    public const string REFUSED = 'refused';

    /** The person rejected the proposal, or it was superseded by a question; it never ran. */
    public const string REJECTED = 'rejected';

    /** The statuses of a call that has not ended; only these rows are written again. */
    public const array OPEN = [self::CALLED, self::PROPOSED, self::APPROVED, self::RUNNING];

    protected $table = 'agent_tool_calls';

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'decided_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<AgentTurn, $this> */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(AgentTurn::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Rows older than tool_log.keep_days go; null keeps them all. Run `model:prune --model=…` daily. */
    public function prunable(): Builder
    {
        $days = config('packstub-agents.tool_log.keep_days');

        if (! $days) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays((int) $days));
    }
}
