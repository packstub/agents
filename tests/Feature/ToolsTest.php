<?php

use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\RenameWidget;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

// What the app's service provider registers: the agent, the server with the tool list, the resources, the abilities.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Agents::roleLabelUsing(fn () => Abilities::$role);
});

it('reads the tool list from the server class and wraps writes for approval', function () {
    actingAs($this->user());

    expect(Agents::toolClasses())->toBe([ListWidgets::class, RenameWidget::class, DrawChart::class])
        ->and(Agents::agentClass())->toBe(WidgetAgent::class)
        ->and(Agents::name())->toBe('Ask Widgets');

    $tools = collect((new WidgetAgent)->tools())->keyBy(fn ($t) => $t->name());

    expect($tools->get('list-widgets'))->toBeInstanceOf(McpServerTool::class)->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('rename-widget'))->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('draw-chart'))->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools)->toHaveCount(3);
});

it('asks for approval with the call as a question: the tool\'s own sentence, or its title and the first argument', function () {
    actingAs($this->user());

    // A tool that describes its calls.
    $rename = new ApprovableTool(app(RenameWidget::class));
    $approval = $rename->shouldRequestApproval(new AiRequest(['id' => 7, 'name' => 'Alpha IV'], 'c1'));
    expect($approval?->reason)->toBe('Rename widget #7 to Alpha IV?');

    // One that does not: the title, the first scalar argument, a question mark.
    $retire = new ApprovableTool(app(RetireWidget::class));
    expect($retire->shouldRequestApproval(new AiRequest(['id' => 12], 'c2'))?->reason)->toBe('Retire Widget 12?')
        ->and(ApprovableTool::question(app(RetireWidget::class), []))->toBe('Retire Widget?')
        ->and(ApprovableTool::question(app(RetireWidget::class), ['ids' => [1, 2], 'force' => true]))->toBe('Retire Widget true?');
});

it('gives each person only the tools their abilities allow, in the list and on a direct call', function () {
    actingAs($this->user());
    $this->widgets();
    Abilities::$allowed = ['widgets.view'];
    Abilities::$role = 'Viewer';

    WidgetServer::tool(ListWidgets::class, [])->assertOk()->assertSee('Alpha');
    WidgetServer::tool(RenameWidget::class, ['id' => 1, 'name' => 'X'])->assertHasErrors()->assertSee('not found');

    $direct = app(RenameWidget::class)->handle(new Request(['id' => 1, 'name' => 'X']));
    expect($direct->isError())->toBeTrue()->and((string) $direct->content())->toContain('Your role (Viewer) is not allowed');

    $names = collect((new WidgetAgent)->tools())->map(fn ($t) => $t->name())->all();
    expect($names)->toContain('list-widgets', 'draw-chart')->not->toContain('rename-widget');

    Abilities::$role = null;
    $direct = app(RenameWidget::class)->handle(new Request(['id' => 1, 'name' => 'X']));
    expect((string) $direct->content())->toContain('You are not allowed');
});

it('hands domain errors back to the model as tool errors', function () {
    actingAs($this->user());

    $response = app(RenameWidget::class)->handle(new Request(['id' => 99, 'name' => 'X']));

    expect($response->isError())->toBeTrue()->and((string) $response->content())->toContain('No widget matches 99');
});

it('serves MCP over HTTP with a read or write token', function () {
    $user = $this->user();
    $this->widgets();
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    $write = $user->createToken('desk', ['read', 'write'])->plainTextToken;
    // The headers a real MCP client sends.
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], $mcp)->assertStatus(401);

    // A read token lists the read tools only.
    $listed = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers($read))
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list-widgets')
        ->json('result.tools.*.name');
    expect($listed)->toBe(['list-widgets', 'draw-chart']);

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list-widgets', 'arguments' => ['limit' => 1]]], $headers($read))
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    // A read token cannot write, even for a person whose role could: the write tool is not on its list.
    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Renamed']]], $headers($read))
        ->assertOk()
        ->assertJsonPath('error.message', 'Tool [rename-widget] not found.');
    expect(app(RenameWidget::class)->tokenRefusal())->toBe('This access token is read-only.');

    // Each MCP request is its own request in production; the test kernel keeps the resolved guard, so reset it.
    auth()->forgetGuards();

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Renamed']]], $headers($write))
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(Widget::query()->find(1)->name)->toBe('Renamed');
});

it('limits a scoped token to the tools it names, in the list and by name', function () {
    $user = $this->user();
    $this->widgets();
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;
    $call = fn (int $id, string $tool, array $args, string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], $headers($token));

    $scoped = $user->createToken('queue', ['read', 'write', 'tool:rename-widget'])->plainTextToken;

    expect(postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers($scoped))->assertOk()->json('result.tools.*.name'))
        ->toBe(['rename-widget']);

    $call(2, 'rename-widget', ['id' => 1, 'name' => 'Scoped'], $scoped)->assertOk()->assertJsonPath('result.isError', false);
    expect(Widget::query()->find(1)->name)->toBe('Scoped');

    // A tool outside the scope is unknown to the server, even though the role allows it.
    $call(3, 'list-widgets', [], $scoped)->assertOk()->assertJsonPath('error.message', 'Tool [list-widgets] not found.');

    // The gate itself, as an app's own tool would see it.
    $refusal = app(ListWidgets::class)->tokenRefusal();
    expect($refusal)->toBe('This access token does not include list-widgets.')
        ->and(app(RenameWidget::class)->tokenRefusal())->toBeNull()
        ->and(AgentTool::tokenTools(AgentTool::accessToken()))->toBe(['rename-widget']);

    // A scope on a read token cannot smuggle a write tool in.
    auth()->forgetGuards();
    $readScoped = $user->createToken('report', ['read', 'tool:rename-widget', 'tool:list-widgets'])->plainTextToken;
    expect(postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], $headers($readScoped))->assertOk()->json('result.tools.*.name'))
        ->toBe(['list-widgets']);

    // An expired token is refused before any tool runs.
    auth()->forgetGuards();
    $expired = $user->createToken('old', ['read'], now()->subMinute())->plainTextToken;
    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], $headers($expired))->assertStatus(401);

    // The chat has no token: every tool the role allows.
    auth()->forgetGuards();
    actingAs($user);
    expect(app(RenameWidget::class)->tokenRefusal())->toBeNull()
        ->and(collect((new WidgetAgent)->tools())->map(fn ($t) => $t->name())->all())->toContain('list-widgets', 'rename-widget');
});

it('builds the prompt from the persona, the domain, the generic rules and the live context', function () {
    actingAs($this->user(['name' => 'Grace Hopper']));
    $this->widgets();
    config(['packstub-agents.max_steps' => 7]);

    $agent = new WidgetAgent(modelKey: 'deep');
    $static = $agent->staticInstructions();
    $dynamic = $agent->dynamicInstructions();

    expect($static)->toStartWith('You are Ask Widgets')
        ->toContain('## What the workspace is', 'draft, live, retired', '## How to work', 'draw-chart', '## How to answer')
        // The rule about live tables is written only when show-table is served (a panel with agent resources).
        ->not->toContain('show-table')
        ->and($dynamic)->toContain('## Now', 'Grace Hopper', 'role Owner', 'Answer language: English', 'Widgets in the catalogue: 2.')
        // The system prompt is the static block alone, byte-identical between turns; the dynamic block goes with the question.
        ->and($agent->instructions())->toBe($static)
        ->and($agent->maxSteps())->toBe(7)
        ->and($agent->providerOptions('anthropic'))->toHaveKeys(['system', 'output_config'])
        ->and($agent->providerOptions('anthropic')['system'])->toBe([['type' => 'text', 'text' => $static, 'cache_control' => ['type' => 'ephemeral']]])
        ->and(WidgetAgent::supportsReasoning('gpt-5.2'))->toBeTrue()
        ->and(WidgetAgent::supportsReasoning('gpt-4o'))->toBeFalse();
});
