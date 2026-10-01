<?php

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * A row in the vocabulary laravel/ai 0.x wrote (tool_calls, tool_results, approval_state), as the table stores it now:
 * the steps and the status, the way the upgrade migration rewrites existing rows.
 */
function legacyRow(array $row): array
{
    if (isset($row['steps'])) {
        return $row;
    }

    [$steps, $status] = AgentConversationStore::stepsFromLegacyRow($row);
    unset($row['tool_calls'], $row['tool_results'], $row['approval_state']);

    return $row + ['steps' => $steps, 'status' => $status];
}

/** A conversation of the person with the given rows (role, content, tool calls…), ids ordered in time. */
function conversationWith(object $user, array $rows, string $title = 'Renames'): string
{
    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => $title]);
    $at = now()->subMinutes(10);

    foreach ($rows as $row) {
        usleep(1100);
        ConversationMessage::query()->create(legacyRow($row + [
            'id' => (string) Str::uuid7(), 'created_at' => $at = $at->addMinute(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
            'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => [], 'meta' => [], 'usage' => [],
        ]));
    }

    return $conversation->id;
}
