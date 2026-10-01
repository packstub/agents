<?php

namespace Packstub\Agents\Ai\Side;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Titles a new chat from its first question: `title`, three to five words in the question's language. */
class TitleAgent extends SideAgent
{
    protected static ?string $textField = 'title';

    public function instructions(): string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. No quotes and no closing punctuation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The title: 3-5 words, in the language of the message.')->required(),
        ];
    }
}
