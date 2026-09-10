# Agents for Laravel

<div class="filament-hidden">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/agents.svg?style=flat-square)](https://packagist.org/packages/packstub/agents)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/agents/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/agents/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/agents.svg?style=flat-square)](https://packagist.org/packages/packstub/agents)
[![License](https://img.shields.io/packagist/l/packstub/agents.svg?style=flat-square)](https://github.com/packstub/agents/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

An AI agent and an MCP server for your Laravel app, built on [laravel/ai](https://github.com/laravel/ai) and [laravel/mcp](https://github.com/laravel/mcp). Write a tool once and it serves both your own assistant and Claude Code, Claude Desktop, Cursor or any other MCP client, with your app's own authorization deciding who may run it. [Filament Agents](https://packstub.dev/docs/filament-agents) puts a chat and the operator pages on top of it inside a Filament panel.

## Features

- **[One tool list, two front doors](#writing-tools)** — every capability is a `laravel/mcp` tool. Your agent calls it through laravel/ai's bridge; external agents call it over HTTP with a Sanctum token. Add a tool to the list and it is everywhere.
- **[Authorization is your app's](#writing-tools)** — a tool declares the ability string that gates the action it mirrors. The agent can never do more than the signed-in person could by hand, and a token narrows that further for external agents: read-only, or just the tools they need.
- **[Writes are proposals](#writing-tools)** — a write tool is wrapped for approval (laravel/ai approvals), so a turn pauses until the person decides; the proposal is a question in the person's words ("Confirm order RO-00016 for Acme?") from the tool's own `describe()`. Over MCP, a write token runs it directly with the person's role.
- **[Turns that survive the request](#running-a-turn)** — every answer is produced by a queued job that records its progress, tokens, tools and how it ended in `agent_turns`; a poll endpoint reads it back. No worker? A sync driver runs the job inside the request.
- **[A bounded bill](#budgets-and-limits)** — a per-user burst limit, answers per day and tokens per day and per month per workspace, tokens per day and per month per user, and a prompt length cap, all checked before a turn reaches the provider and overridable per workspace and per user in `agent_limits`.
- **[Your assistant, your prompt](#the-agent)** — a scaffolded agent class with two slots to fill (who it is, what the workspace is) on top of generic working and answering rules, provider-cached instructions and a model catalog (Claude Opus 5, Claude Haiku 4.5, Claude Opus 5 · Deep) for Anthropic, OpenAI, Gemini or xAI — any other laravel/ai provider, Ollama included, runs on its smartest and cheapest models. A failover list (`AGENT_FAILOVER=gemini,openai`) keeps answering when a provider is overloaded.
- **[Tenancy-aware](#tenancy)** — the MCP path can carry the workspace, tokens are bound to it, conversations can live in the tenant database and a workspace can bring its own provider key. Works without tenancy too.
- **Translatable** — every string goes through `__()`, with German, Spanish, Romanian and Russian included.

## Compatibility

| Package | Laravel | PHP | laravel/ai | laravel/mcp |
| --- | --- | --- | --- | --- |
| 1.x | 13.x | 8.4+ | ^0.11 | ^0.9 |

## Installation

```bash
composer require packstub/agents
php artisan packstub-agents:install
```

The install command publishes the config, offers to run the migrations and scaffolds `app/Ai/Agents/Assistant.php`. Register the agent and the tools in a service provider and put a provider key in `.env` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY` or `XAI_API_KEY`, with `AGENT_PROVIDER=anthropic|openai|gemini|xai`):

```php
use Packstub\Agents\Facades\Agents;

public function boot(): void
{
    Agents::useAgent(\App\Ai\Agents\Assistant::class);
    Agents::useServer(\App\Mcp\Servers\AcmeServer::class);
    Agents::authorizeUsing(fn (string $ability) => auth()->user()->can($ability));
}
```

The MCP endpoint answers without a key, since it does not need a model. Read more: [Installation](https://packstub.dev/docs/agents/installation).

## In a Filament panel

[packstub/filament-agents](https://github.com/packstub/filament-agents) requires this package and adds what a panel shows: the chat pages with an "Ask …" button, approve-in-chat writes, `show-table` rendering a resource's own table under an answer, the Agent access page that mints tokens, and the operator's AI limits and AI turns pages. Install it instead of this package in a panel app; everything on this page applies unchanged, registered through `AgentsPlugin` instead of the facade. Read more: [Filament Agents](https://packstub.dev/docs/filament-agents).

## Writing tools

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
php artisan packstub-agents:tool ConfirmOrder --write --ability=orders.manage
```

A tool extends `Packstub\Agents\Mcp\AgentTool`, declares its `$ability`, and implements `run(Request): array` and `schema(JsonSchema): array`. Mark reads with `#[IsReadOnly]`; anything else is approval-gated for the agent and needs a write token over MCP. Domain exceptions come back to the model as tool errors, never to the person as a crash.

```php
#[IsReadOnly]
#[Description('Find orders by number, customer, status and date.')]
class SearchOrders extends AgentTool
{
    protected ?string $ability = 'orders.view';

    protected function run(Request $request): array
    {
        $filters = AgentResources::normalizeFilters('orders', (array) $request->get('filters'));
        $query = AgentResources::apply('orders', Order::query(), $filters);

        return [
            'total' => $query->count(),
            'rows' => $query->limit($this->limit($request))->get()->map(fn (Order $o) => Orders::agentSummary($o))->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filters' => $schema->object(AgentResources::filterSchema($schema, 'orders')),
            'limit' => $schema->integer(),
        ];
    }
}
```

List the tools on the server class, reads first. The agent reads the same list:

```php
class AcmeServer extends \Packstub\Agents\Mcp\AgentServer
{
    protected string $name = 'Acme';

    protected string $instructions = 'The back office of an online shop. Start with search-orders.';

    protected array $tools = [
        Tools\SearchOrders::class,
        \Packstub\Agents\Mcp\Tools\DrawChart::class,
        Tools\ConfirmOrder::class,
    ];
}
```

`Agents::useTools([...])` works instead of a server class. A write tool may add `describe(array $arguments): ?string` to phrase its own proposals ("Confirm order RO-00016 for Acme?"); without one the question is the tool's title and its first argument. Read more: [Tools](https://packstub.dev/docs/agents/tools).

## The agent

`packstub-agents:agent` scaffolds `App\Ai\Agents\Assistant`, a subclass of `Packstub\Agents\Ai\Agent` with two slots to fill: `persona()` (who it is) and `domain()` (what the workspace is). The base class supplies the generic working and answering rules, the dynamic context (date, workspace, person, role, language — sent with the question, so the system prompt and the history stay cacheable) and the provider options (Anthropic cache breakpoints on the instructions and the settled history, reasoning effort or thinking level per model). Append to any of them by overriding `workRules()`, `answerRules()` or `context()` and merging the parent's list.

```php
class Assistant extends Agent
{
    protected function persona(): string
    {
        return 'You are Ask Acme, the back-office assistant of an online shop.';
    }

    protected function domain(): string
    {
        return <<<'PROMPT'
        - Orders move from placed to paid to shipped; a cancelled order keeps its number.
        - Warehouse staff may confirm and ship; only managers may refund.
        PROMPT;
    }
}
```

Read more: [The agent](https://packstub.dev/docs/agents/assistant).

## Running a turn

Start a conversation, queue a turn, poll it. The `RunAgentTurn` job restores who asked and where (the person, the guard, the workspace, the locale), runs the middleware pipeline with the budget check, streams the answer from the provider and records what it cost:

```php
$conversation = app(AgentConversationStore::class)->startConversation($user, $question);
$turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => $question], null, 'auto', null); // model key, page context ("orders/12")
```

`GET agents/chat/{conversation}/turn` returns the answer so far and a status line; run `php artisan queue:work`, or set `AGENT_TURN_DRIVER=sync` to run the job inside the request — a turn no worker takes within `AGENT_WORKER_WAIT` seconds says so on that status line. Long chats replay a token-budgeted window with a rolling summary. Read more: [The agent](https://packstub.dev/docs/agents/assistant#how-a-turn-runs).

## Filters and charts

A class implementing `AgentResource` gives the model a filter vocabulary and a record summary, shared by every search tool that names the same key:

```php
class Orders implements AgentResource
{
    public static function agentKey(): string { return 'orders'; }

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return ['number' => $record->number, 'status' => $record->status, 'url' => route('orders.show', $record)];
    }

    public static function agentFilters(): array
    {
        return [
            Filter::text('query')->description('Order number or customer.')
                ->apply(fn (Builder $q, string $t) => $q->where('number', 'like', "%{$t}%")),
            Filter::enum('status', OrderStatus::class)->multiple()
                ->apply(fn (Builder $q, array $s) => $q->whereIn('status', $s)),
            Filter::date('placed_from')
                ->apply(fn (Builder $q, string $d) => $q->where('placed_at', '>=', $d)),
        ];
    }
}
```

Register it with `Agents::useResources([Orders::class])`. `draw-chart` renders bar, line, pie and doughnut charts from numbers the model already retrieved, and any tool can return a `chart` key of its own. Read more: [Tables and charts](https://packstub.dev/docs/agents/tables-and-charts).

## MCP clients

Mint a Sanctum token with the abilities the client should have — `read`, `write`, `tool:{name}` to scope it, `tenant:{slug}` to bind it to a workspace — and connect any MCP client to `POST /mcp`:

```php
$token = $user->createToken('laptop', ['read', 'tool:search-orders'], now()->addDays(30))->plainTextToken;
```

```bash
claude mcp add --transport http acme https://acme.test/mcp --header "Authorization: Bearer 3|…"
```

The endpoint sits behind `throttle`, `auth:sanctum` and the package's own middleware, so external agents get exactly the tools the person's role and their token allow. Read more: [MCP clients](https://packstub.dev/docs/agents/mcp-clients).

## Budgets and limits

`config/packstub-agents.php` holds the platform ceiling (`AGENT_TURNS_PER_MINUTE`, `AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`, `AGENT_USER_TOKENS_PER_DAY`, `AGENT_USER_TOKENS_PER_MONTH`, `AGENT_PROMPT_MAX_CHARS`). Rows in `agent_limits` override it: one global row, optional rows per workspace and per user, empty fields inherit. `AgentBudget::summary()` gives the numbers for a settings page, and every ended turn keeps its record (provider, model, tokens, tools, duration, how it ended), optionally as one log line. Read more: [Budgets and limits](https://packstub.dev/docs/agents/budgets-and-limits).

## Tenancy

Tell the package what a workspace is and how the current one is found:

```php
Agents::tenantModel(Team::class, slugAttribute: 'slug');
Agents::tenantUsing(fn () => auth()->user()?->currentTeam);
Agents::enteringTenant(function (Team $team) {
    tenancy()->initialize($team);

    return fn () => tenancy()->end();
});
```

Set the MCP path with the workspace in it (`'mcp' => ['path' => 'mcp/{tenant}']`) and the middleware looks the workspace up by its slug, checks the person's membership and the token's `tenant:{slug}` ability, and enters it before any tool runs. A workspace can bring its own provider key through `credentialsUsing()`. For a database-per-tenant app set `'run_migrations' => false`, publish the migrations, keep `create_agent_limits_table` central and move the chat tables next to your tenant migrations. Read more: [Tenancy](https://packstub.dev/docs/agents/tenancy).

## Configuration

```php
Agents::useAgent(Assistant::class);                       // your Agent subclass
Agents::useServer(AcmeServer::class);                     // the MCP server class with the tool list
Agents::useTools([...]);                                  // or a plain tool list
Agents::addTools([...]);                                  // tools appended to the server's own list
Agents::useResources([Orders::class]);                    // the AgentResource classes
Agents::useMiddleware([AuditTurns::class]);               // your own agent middleware
Agents::authorizeUsing(fn (string $ability) => ...);      // how an ability is checked for the current person
Agents::roleLabelUsing(fn () => ...);                     // the person's role, for the prompt and refusals
Agents::credentialsUsing(fn () => new WorkspaceCredentials(...)); // a workspace's own provider key
Agents::limitsAuthorizeUsing(fn () => ...);               // who may edit the agent_limits rows
Agents::tenantUsing(fn () => ...);                        // the current workspace
```

Read more: [Configuration](https://packstub.dev/docs/agents/configuration).

## Documentation

- [Installation](https://packstub.dev/docs/agents/installation)
- [Tools](https://packstub.dev/docs/agents/tools)
- [The agent](https://packstub.dev/docs/agents/assistant)
- [Tables and charts](https://packstub.dev/docs/agents/tables-and-charts)
- [MCP clients](https://packstub.dev/docs/agents/mcp-clients)
- [Budgets and limits](https://packstub.dev/docs/agents/budgets-and-limits)
- [Tenancy](https://packstub.dev/docs/agents/tenancy)
- [Configuration](https://packstub.dev/docs/agents/configuration)
- [Security](https://packstub.dev/docs/agents/security)
- [Testing](https://packstub.dev/docs/agents/testing)

## Testing

```bash
composer test
```

In your own app, fake the model with `Assistant::fake([...])` and drive tools through `AcmeServer::tool(ToolClass::class, [...])`. Never call a provider from tests. Read more: [Testing](https://packstub.dev/docs/agents/testing).

## Changelog

See the [changelog](https://github.com/packstub/agents/blob/main/CHANGELOG.md).

## Security vulnerabilities

This package lets a model act inside your app, so we take reports seriously. Please e-mail [support@packstub.dev](mailto:support@packstub.dev) rather than opening a public issue. The threat model is documented on the [Security](https://packstub.dev/docs/agents/security) page.

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/agents/contributors)

## License

MIT. See the [license file](https://github.com/packstub/agents/blob/main/LICENSE.md).
