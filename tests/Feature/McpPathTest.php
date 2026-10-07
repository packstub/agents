<?php

use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\postJson;

// The default path, "mcp", with no {tenant}: fine for an app without workspaces, refused once one has them.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useTools([WhoAmI::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('refuses the MCP path without {tenant} once the app has workspaces', function () {
    $owner = $this->user();
    $this->team($owner, 'acme');
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $call = fn (string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'who-am-i', 'arguments' => []]], ['Authorization' => 'Bearer '.$token] + $mcp);

    // No workspaces: the path serves the person as themselves.
    $token = $owner->createToken('laptop', ['read'])->plainTextToken;
    $seen = json_decode($call($token)->assertOk()->json('result.content.0.text'), true);
    expect($seen['user'])->toBe($owner->id)->and($seen['tenant'])->toBeNull();

    // Workspaces, but the path names none: nothing would scope the tools, so the request is refused.
    Agents::tenantModel(Team::class, 'slug');
    auth()->forgetGuards();
    $bound = $owner->createToken('desk', ['read', 'tenant:acme'])->plainTextToken;
    $call($bound)->assertNotFound()->assertJsonPath('error', fn (string $e) => str_contains($e, 'mcp/{tenant}'));
    $call($token)->assertNotFound();
});
