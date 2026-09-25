<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An earlier answer to a question, kept when the answer was produced again
 * (Regenerate) or the question edited (Edit): the rows that followed the
 * question, as they were, so a chat surface can page through the versions
 * and put one back. Lives next to the conversations.
 */
class AgentAnswerVersion extends Model
{
    protected $table = 'agent_answer_versions';

    protected $guarded = [];

    protected $casts = [
        'rows' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }
}
