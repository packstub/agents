# Agents for Laravel

An AI agent and an MCP server for a Laravel app, built on laravel/ai and laravel/mcp. One tool list serves both: your own assistant and Claude Code, Claude Desktop, Cursor or any other MCP client, with your app's own authorization deciding who may run what. Free and open source (MIT).

- Repository: [github.com/packstub/agents](https://github.com/packstub/agents)
- Packagist: [packstub/agents](https://packagist.org/packages/packstub/agents)
- Support: [GitHub issues](https://github.com/packstub/agents/issues)

In a Filament panel, [Filament Agents](https://packstub.dev/docs/filament-agents) puts a chat, the Agent access page and the operator pages on top of this package.

## What you get

| Feature | What it means for you |
| --- | --- |
| **One tool list, two front doors** | Every capability is a `laravel/mcp` tool class with an ability. Your agent calls it through laravel/ai; external agents call it over HTTP with a Sanctum token. Add a tool to the list once and it is everywhere. |
| **Your app's authorization** | A tool declares the same ability string that gates the action it mirrors. The agent can never do more than the signed-in person could by hand. A token narrows that further for external agents: read-only, or just the tools they need. |
| **Writes are proposals** | Read-only tools run directly. Any other tool is wrapped for approval: the turn pauses on what would run until the person approves or rejects it, shown as a question in the person's words from the tool's own `describe()`. Over MCP, a write token runs it directly with the person's role. |
| **Turns that survive the request** | Every answer is produced by a queued job that streams its progress into `agent_turns` and keeps the record when it ends: provider, model, tokens, tools, duration, how it ended. Stop cuts it short, follow-ups wait their turn per conversation, a poll endpoint reads the answer so far with a status line that names a missing worker. Long chats replay a token-budgeted window with a rolling summary. No worker? A sync driver runs the job inside the request. |
| **Beyond the chat** | `AgentRun` asks as a person from a command, a job or the scheduler and returns the answer; the email channel answers a person's mail and continues the chat on a reply; the MCP server serves the app's resources and one record as MCP resources and the starter questions as prompts; four events (turn started, tool called, proposal decided, turn ended) feed an audit trail or a notification; `AgentEval` asserts in a test which tools the agent called with which arguments. |
| **A bounded bill** | A per-user burst limit, answers and tokens per day and per month per workspace, tokens per day and per month per user, and a prompt length cap, checked before a turn reaches the provider. Rows in `agent_limits` override them per workspace and per user. |
| **Your assistant, your prompt** | A scaffolded agent class with two slots (who it is, what the workspace is) on top of generic working and answering rules; the static block and the settled history are cached by the provider, the dynamic block (date, person, role, language) rides with the question. Anthropic, OpenAI, Gemini or xAI with a model catalog (Claude Opus 5, Claude Haiku 4.5, Claude Opus 5 · Deep); any other laravel/ai provider, Ollama included, on its smartest and cheapest models; a failover list keeps answering when a provider is overloaded. |
| **Tenancy-aware** | The MCP path can carry the workspace, tokens are bound to it, conversations can live in the tenant database and a workspace can bring its own provider key. Works without tenancy too. |
| **Translatable** | Every string goes through `__()`; German, Spanish, Romanian and Russian are included. |

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the install command, registering through the `Agents` facade, workspaces, the queue worker (or the sync driver), the routes, the provider key |
| [Tools](tools.md) | Writing an `AgentTool`, abilities, read-only versus write tools, the proposal as a question (`describe()`), the server class, errors, the scaffold command |
| [The agent](assistant.md) | The `Agent` class with its persona, domain, rules, context and middleware; how a turn runs, the poll endpoint and its status line, Stop, long chats, approvals, models, failover |
| [Tables and charts](tables-and-charts.md) | `AgentResource`, the `Filter` vocabulary, `AgentResources`, `draw-chart`, page context |
| [MCP clients](mcp-clients.md) | Tokens, abilities, tool scopes and expiry, connecting Claude Code, Claude Desktop and Cursor, the endpoint's middleware |
| [Budgets and limits](budgets-and-limits.md) | The platform ceiling in config, the `agent_limits` rows, inheritance, `AgentBudget`, what each turn cost: the record and the log line |
| [Tenancy](tenancy.md) | Workspaces, the `{tenant}` path, workspace-bound tokens, per-workspace keys and limits, database-per-tenant migrations |
| [Configuration](configuration.md) | Every config key and environment variable, the `Agents` facade |
| [Security](security.md) | The threat model: trust boundaries, prompt injection, what the package enforces and what stays yours |
| [Testing](testing.md) | Faking the model, driving tools, running a turn, testing the MCP endpoint in your app |

## At a glance

```bash
composer require packstub/agents
php artisan packstub-agents:install
php artisan packstub-agents:tool SearchOrders --ability=orders.view
```

```php
use Packstub\Agents\Facades\Agents;

public function boot(): void
{
    Agents::useAgent(\App\Ai\Agents\Assistant::class);
    Agents::useServer(\App\Mcp\Servers\AcmeServer::class);
    Agents::authorizeUsing(fn (string $ability) => auth()->user()->can($ability));
}
```

Put `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY` or `XAI_API_KEY` in `.env` (with `AGENT_PROVIDER`), mint a token with `$user->createToken('laptop', ['read'])`, and connect Claude Code to `POST /mcp`.
