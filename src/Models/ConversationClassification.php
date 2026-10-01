<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a chat is about, how the person sounds and whether they got what they
 * came for, as the ClassifierAgent side agent read it after the last answer
 * (config `classify`). One row per conversation, next to the conversations;
 * a list of chats filters and sorts by it.
 */
class ConversationClassification extends Model
{
    protected $table = 'agent_conversation_classifications';

    protected $guarded = [];

    protected $casts = [
        'resolved' => 'boolean',
    ];

    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }
}
