# Testing

Never call a provider from tests. laravel/ai fakes the model, laravel/mcp drives tools directly, and the HTTP endpoints are normal routes.

## Faking the model

```php
use App\Ai\Agents\Assistant;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentTurns;

it('answers a question', function () {
    actingAs($user);
    Assistant::fake(['Two orders are waiting for a call.']);

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'What needs attention?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'What needs attention?'], null, 'auto', null);

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation)->latest('id')->value('content'))
        ->toBe('Two orders are waiting for a call.');
});
```

`Assistant::fake([...])` comes from laravel/ai's `Promptable` trait: each entry is one answer, in order.

A turn runs in a queued job. On the `sync` queue driver (the default in a test environment), or with `chat.driver` set to `sync`, it runs inside `enqueue()`, so the answer is stored when the call returns, as above. To test what happens while the job waits — the poll endpoint attaching to a running turn, Stop, the follow-ups waiting per conversation — fake the queue and run the pushed job yourself:

```php
use Illuminate\Support\Facades\Queue;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Support\AgentTurns;

Queue::fake();
$turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'What needs attention?'], null, 'auto', null);

Assistant::fake(['Two orders are waiting for a call.']);
Queue::pushed(RunAgentTurn::class)->first()->handle(app(AgentTurns::class));
```

`Queue::pushed(...)->first()` is the job that was dispatched; `handle()` runs it as a worker would, with the workspace, person, guard and locale of the request restored.

**In a Filament panel**, [Filament Agents](https://packstub.dev/docs/filament-agents/testing) drives the same turns through its chat page with Livewire's `livewire(Chat::class)`.

## Driving a tool

```php
use App\Mcp\Servers\AcmeServer;
use App\Mcp\Tools\SearchOrders;

it('finds orders by number', function () {
    actingAs($user);

    AcmeServer::tool(SearchOrders::class, ['query' => 'RO-00012'])
        ->assertOk()
        ->assertSee('RO-00012');
});

it('refuses a tool the role does not allow', function () {
    actingAs($viewer);

    AcmeServer::tool(ConfirmOrder::class, ['id' => 1])
        ->assertHasErrors()
        ->assertSee('not found');   // a tool the role may not use is not registered on the server

    $direct = app(ConfirmOrder::class)->handle(new \Laravel\Mcp\Request(['id' => 1]));
    expect($direct->isError())->toBeTrue()
        ->and((string) $direct->content())->toContain('not allowed');
});
```

`Server::tool()` is laravel/mcp's testing helper: it runs the tool through the server, so the ability check applies exactly as in production.

## The agent's tool list

```php
use Laravel\Ai\Tools\McpServerTool;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Facades\Agents;

it('wraps writes for approval', function () {
    actingAs($user);

    $tools = collect((new Assistant)->tools())->keyBy(fn ($tool) => $tool->name());

    expect($tools->get('search-orders'))->toBeInstanceOf(McpServerTool::class)->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('confirm-order'))->toBeInstanceOf(ApprovableTool::class);
});
```

## The MCP endpoint

```php
use function Pest\Laravel\postJson;

it('serves MCP with a read or write token', function () {
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    $write = $user->createToken('desk', ['read', 'write'])->plainTextToken;

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], $mcp)
        ->assertStatus(401);

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$read] + $mcp)
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'search-orders');

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'confirm-order', 'arguments' => ['id' => 1]]], ['Authorization' => 'Bearer '.$read] + $mcp)
        ->assertOk()
        ->assertJsonPath('error.message', 'Tool [confirm-order] not found.');   // a read token does not list write tools

    auth()->forgetGuards();   // each MCP request is its own request in production; the test kernel keeps the resolved guard

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'confirm-order', 'arguments' => ['id' => 1]]], ['Authorization' => 'Bearer '.$write] + $mcp)
        ->assertOk()
        ->assertJsonPath('result.isError', false);
});
```

## Budgets

```php
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;

it('stops a turn when the daily limit is spent', function () {
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 1]);
    AgentLimits::flush();

    // … one assistant message exists …

    expect(AgentBudget::refusal('hello'))->toContain("today's limit");
});
```

## The package's own suite

```bash
composer test
```

The suite runs on Orchestra Testbench with an in-memory SQLite database and no Filament: a `Widget` model with a plain `AgentResource` class, four tools, an agent and a server registered through the facade, and covers tools and authorization, tokens and scopes, queued turns on their guard and workspace, the poll endpoint, budgets and limits.
