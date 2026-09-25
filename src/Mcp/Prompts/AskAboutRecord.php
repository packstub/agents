<?php

namespace Packstub\Agents\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\PageContext;

/**
 * The starter questions about one record, the way the chat opened from that
 * record offers them (Agent::suggestions with a page context), with the
 * record's summary attached so the client's model starts informed.
 */
#[Name('ask-about-record')]
#[Title('Ask about a record')]
#[Description('What to know, and what to do next, about one record: pass a resource key and an id (see agents://resources).')]
class AskAboutRecord extends Prompt
{
    public function shouldRegister(): bool
    {
        return Agents::resourceClasses() !== [];
    }

    public function arguments(): array
    {
        return [
            new Argument(name: 'resource', description: 'The resource key, e.g. orders', required: true),
            new Argument(name: 'id', description: 'The record id', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        $ref = $request->get('resource').'/'.$request->get('id');
        $context = PageContext::resolve($ref);

        if ($context === null) {
            return Response::error(__('No :resource record matches :id, or it cannot be viewed.', ['resource' => (string) $request->get('resource'), 'id' => (string) $request->get('id')]));
        }

        $questions = implode("\n", array_map(fn (string $q) => '- '.$q, Agents::agent($ref)->suggestions()));

        return Response::text(
            "About {$context['label']}:\n".json_encode($context['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n".
            "Answer these, using the tools where the summary is not enough:\n{$questions}"
        );
    }
}
