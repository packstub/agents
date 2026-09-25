<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/** A conversation the person pinned to the top of their list (next to the conversations; laravel/ai's table is not touched). */
class AgentPinnedConversation extends Model
{
    protected $table = 'agent_pinned_conversations';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }
}
