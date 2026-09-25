<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thumbs up / down on one assistant message (next to the conversations),
 * with the person's note when they left one and the turn that produced the
 * answer, so an operator's turn log can show the rating next to the cost.
 */
class AgentMessageFeedback extends Model
{
    protected $table = 'agent_message_feedback';

    protected $guarded = [];
}
