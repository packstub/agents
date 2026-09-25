<?php

namespace Packstub\Agents\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\Prompts\AskAboutRecord;
use Packstub\Agents\Mcp\Prompts\WhatNeedsAttention;
use Packstub\Agents\Mcp\Resources\RecordResource;
use Packstub\Agents\Mcp\Resources\ResourcesIndex;
use Packstub\Agents\Mcp\Tools\DrawChart;
use ReflectionProperty;

/**
 * The product as an MCP server: POST {mcp.path} with a bearer token from the
 * Agent access page, and Claude Code, Claude Desktop or any MCP client works
 * inside the workspace with the person's own role.
 *
 * Subclass it to give the server its name, version, instructions and the
 * tool list (`protected array $tools = [...]`, reads first); the chat agent
 * reads that same list, so adding a tool there adds it everywhere. Used as
 * is, it serves the tools registered with AgentsPlugin::tools() or
 * Agents::useTools() under the assistant's name, or — with none — the
 * package's generic tools: draw-chart, plus show-table in a panel with agent
 * resources. Every server also serves the package's resources (the app's
 * resources and one record) and prompts (the starter questions), which a
 * subclass may replace with its own lists.
 */
class AgentServer extends Server
{
    protected string $name = 'Assistant';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server exposes an application to agents. You see the tools the person's role and their access token
        allow (a token may be read-only or limited to a few tools); write tools run immediately with the token holder's
        role when the token may write, so read the record first when in doubt.
        MARKDOWN;

    /** @var list<class-string<Tool>> */
    protected array $tools = [DrawChart::class];

    /**
     * What a client reads without a tool call: the app's resources (keys and filters) and one record by key and id,
     * both only with agent resources registered. A subclass lists its own.
     *
     * @var list<class-string<Server\Resource>>
     */
    protected array $resources = [ResourcesIndex::class, RecordResource::class];

    /**
     * The starter questions as prompts a client can pick: the workspace's, and those about one record.
     *
     * @var list<class-string<Prompt>>
     */
    protected array $prompts = [WhatNeedsAttention::class, AskAboutRecord::class];

    public int $defaultPaginationLength = 50;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        // A server class that lists its own tools keeps them; the base list is what the app registered, or the generic tools.
        if ($this->tools === [] || (new ReflectionProperty($this, 'tools'))->getDeclaringClass()->getName() === self::class) {
            $this->tools = Agents::toolClasses();
        }

        if ($this->name === 'Assistant') {
            $this->name = Agents::name();
        }
    }
}
