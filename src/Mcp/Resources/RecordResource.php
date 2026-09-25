<?php

namespace Packstub\Agents\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Support\PageContext;

/**
 * One record of an agent resource, as the app summarizes it for the model
 * (AgentResource::agentSummary with every field): record://orders/12. An
 * MCP client attaches it to a prompt ("@orders/12, what is the next step?")
 * without a tool call. Only the resources the person may view, and only
 * with agent resources registered.
 */
#[Title('A record')]
#[Description('One record of the app by resource key and id — record://orders/12 — summarized as the assistant reads it. See agents://resources for the keys.')]
#[MimeType('application/json')]
class RecordResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('record://{resource}/{id}');
    }

    public function shouldRegister(): bool
    {
        return Agents::resourceClasses() !== [];
    }

    public function handle(Request $request): Response
    {
        $key = (string) $request->get('resource');
        $id = (string) $request->get('id');

        if (! AgentResources::has($key)) {
            return Response::error(__('No resource is called :key.', ['key' => $key]));
        }

        $context = PageContext::resolve("{$key}/{$id}");

        if ($context === null) {
            return Response::error(__('No :resource record matches :id, or it cannot be viewed.', ['resource' => $key, 'id' => $id]));
        }

        return Response::json(['resource' => $key, 'id' => $id, 'label' => $context['label'], 'record' => $context['summary']]);
    }
}
