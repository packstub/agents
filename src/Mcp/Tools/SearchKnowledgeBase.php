<?php

namespace Packstub\Agents\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use RuntimeException;

/**
 * Answers "how do I…" from the app's own documents: a similarity search over
 * the knowledge base the app registered (Agents::knowledgeBase(), or config
 * `knowledge_base`), returning the closest articles for the model to answer
 * from and cite. Registered only while a searchable knowledge base exists,
 * and gated by its ability.
 */
#[IsReadOnly]
#[Description('Search the knowledge base — the guides, policies and how-to articles of this application — for what answers a question. Use it for "how do I…", "what is our policy on…", "where do I find…" before answering from general knowledge, and cite the articles you used. Not for records: those come from the other tools.')]
class SearchKnowledgeBase extends AgentTool
{
    public function shouldRegister(): bool
    {
        $knowledge = Agents::knowledge();

        return $knowledge !== null && $knowledge->searchable() && Agents::allows($knowledge->ability) && $this->tokenRefusal() === null;
    }

    protected function run(Request $request): array
    {
        $knowledge = Agents::knowledge();

        if ($knowledge === null || ! $knowledge->searchable()) {
            throw new RuntimeException(__('There is no knowledge base to search.'));
        }

        if (! Agents::allows($knowledge->ability)) {
            throw new RuntimeException(self::refusal());
        }

        $question = trim((string) $request->get('query'));

        if ($question === '') {
            throw new RuntimeException(__('Say what to look for.'));
        }

        $articles = $knowledge->search($question, $request->get('limit') ? (int) $request->get('limit') : null);

        return [
            'articles' => $articles->all(),
            'total' => $articles->count(),
            'note' => $articles->isEmpty()
                ? 'Nothing in the knowledge base matches. Say so; do not answer this from general knowledge as if it were the application\'s own guidance.'
                : 'Answer from these articles and cite each one you used by its title, as a link when it has a url.',
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to look for, as a question or a few keywords, in the person\'s language.')->required(),
            'limit' => $schema->integer()->description('How many articles at most (default '.(Agents::knowledge()?->limit ?? 5).').'),
        ];
    }
}
