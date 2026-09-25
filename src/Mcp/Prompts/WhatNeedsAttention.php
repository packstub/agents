<?php

namespace Packstub\Agents\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Packstub\Agents\Facades\Agents;

/** The chat's generic starter questions (Agent::suggestions without a page context), as one prompt an MCP client can pick. */
#[Name('what-needs-attention')]
#[Title('What needs attention')]
#[Description('The assistant\'s starter questions for the workspace: what needs attention today, the latest records, what it can help with.')]
class WhatNeedsAttention extends Prompt
{
    public function handle(): Response
    {
        $questions = implode("\n", array_map(fn (string $q) => '- '.$q, Agents::agent()->suggestions()));

        return Response::text("Using the tools of this server, answer:\n{$questions}");
    }
}
