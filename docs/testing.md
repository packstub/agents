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

## Evals

`Packstub\Agents\Testing\AgentEval` asks as a person with the provider faked step by step and asserts on what the agent did — which tools it called with which arguments, what it proposed, what it answered. The whole engine runs (the budget, your middleware, the tools, the conversation store), so a broken tool schema, a wrong ability or a prompt that stopped calling the overview tool fails here before it fails in front of a person:

```php
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\Testing\AgentEval;

AgentEval::as($user)->in($team)
    ->expecting([new ToolCall('c1', 'search-orders', ['filters' => ['status' => ['placed']]]), 'Two orders are waiting.'])
    ->ask('Which orders are waiting?')
    ->assertOk()
    ->assertCalled('search-orders', ['filters' => ['status' => ['placed']]]) // a subset match on the arguments
    ->assertCalledInOrder(['search-orders'])
    ->assertNotCalled('confirm-order')
    ->assertAnswerContains('two');
```

`expecting()` takes what the faked provider answers next, in order: a string is an answer, a `ToolCall` makes the agent run that tool (a write tool becomes a proposal and pauses the turn), a `TextResponse` carries usage, a closure may throw. `ask()` returns an `AgentEvalResult` — `text()`, `toolCalls()` (name, arguments, `readOnly`, `pending`, `rejected`, `result`), `proposals()`, the `answer` (an `AgentAnswer`) — with `assertOk()`, `assertFailed()`, `assertRefused()`, `assertAnswerContains()`, `assertAnswerNotContains()`, `assertCalled()`, `assertNotCalled()`, `assertCalledInOrder()`, `assertProposed()`, `assertNothingProposed()`, `assertNoToolCalls()` and `assertTurnTools()`. `then()` hands the eval back for the next question on the same conversation, `decide($callId, true)` approves a waiting proposal. Under a faked provider laravel/ai does not run the approved tool (test the tool's own `run()` directly, as below); the decision still reaches the prompt and the answer that follows is asserted like any other.

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
