<?php

namespace Packstub\Agents\Mcp\Resources;

use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentResources;

/**
 * agents://resources — the agent resources the person may see, each with its
 * key and the filters its search vocabulary takes, so a client knows what
 * record://{resource}/{id} covers and how show-table filters read.
 */
#[Title('The app\'s resources')]
#[Description('The resources the assistant knows — their keys and filter vocabulary. Read this first to learn what record://{resource}/{id} covers.')]
#[Uri('agents://resources')]
#[MimeType('application/json')]
class ResourcesIndex extends Resource
{
    public function shouldRegister(): bool
    {
        return Agents::resourceClasses() !== [];
    }

    public function handle(): Response
    {
        $list = [];

        foreach (array_keys(AgentResources::all()) as $key) {
            $list[] = [
                'key' => $key,
                'record_uri' => "record://{$key}/{id}",
                'filters' => JsonSchema::object(fn ($schema) => AgentResources::filterSchema($schema, $key))->toArray()['properties'] ?? [],
            ];
        }

        return Response::json(['resources' => $list]);
    }
}
