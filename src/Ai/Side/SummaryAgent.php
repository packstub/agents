<?php

namespace Packstub\Agents\Ai\Side;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Keeps the rolling summary of a long chat: reads the summary so far and the
 * messages that no longer fit the history window, and returns the merged
 * `summary` the assistant reads in their place.
 */
class SummaryAgent extends SideAgent
{
    protected static ?string $textField = 'summary';

    public function timeout(): int
    {
        return 60;
    }

    public function instructions(): string
    {
        return 'You maintain the running summary of a conversation between a person and a back-office assistant. Merge the existing summary (if any) with the new messages into one summary of at most 300 words, in the language of the conversation. Keep every fact, number, record identifier, decision, open question and what the person asked for; drop pleasantries and the assistant\'s wording.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('The merged summary, at most 300 words, in the language of the conversation.')->required(),
        ];
    }
}
