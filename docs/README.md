# Agents for Laravel

An AI agent and an MCP server for a Laravel app, built on laravel/ai and laravel/mcp. One tool list serves both: your own assistant and Claude Code, Claude Desktop, Cursor or any other MCP client, with your app's own authorization deciding who may run what. Free and open source (MIT).

- Repository: [github.com/packstub/agents](https://github.com/packstub/agents)
- Packagist: [packstub/agents](https://packagist.org/packages/packstub/agents)
- Support: [GitHub issues](https://github.com/packstub/agents/issues)

In a Filament panel, [Filament Agents](https://packstub.dev/docs/filament-agents) puts a chat, the Agent access page and the operator pages on top of this package.

## Features

- **[One tool list, two front doors](tools.md)**: your own agent and any MCP client (Claude Code, Cursor) call the same tools.
- **[Your app's authorization](security.md#what-the-package-enforces)**: the agent never does more than the signed-in person could, and a token narrows it further.
- **[Writes are proposals](tools.md#read-only-versus-write)**: a write waits for the person's approval, asked as a plain question with a preview of the change.
- **[Turns that survive the request](assistant.md#how-a-turn-runs)**: queued, recorded and polled, with a sync driver when there is no worker.
- **[Beyond the chat](assistant.md#the-assistant-without-a-chat)**: ask from a command, a job or by email, with events for an audit trail.
- **[Knowledge base and web search](tools.md#knowledge-base)**: answers from your own documents, cited, and from public pages within an allow-list.
- **[Guard rails you switch on](security.md#the-prompt-guard)**: a prompt guard against injections, and redaction of secrets in answers.
- **[A bounded bill](budgets-and-limits.md)**: answer and token limits per user and per workspace, checked before the provider is called.
- **[Your assistant, your prompt](assistant.md#the-agent-class)**: a scaffolded agent class on Anthropic, OpenAI, Gemini or xAI, with failover.
- **[Tenancy-aware](tenancy.md)**: workspace-bound tokens, tenant databases and per-workspace keys, or no tenancy at all.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the install command, registering through the `Agents` facade, workspaces, the queue worker (or the sync driver), the routes, the provider key |
| [Tools](tools.md) | Writing an `AgentTool`, abilities, read-only versus write tools, the proposal as a question (`describe()`), the server class, errors, the scaffold command, the knowledge base and web search |
| [The agent](assistant.md) | The `Agent` class with its persona, domain, rules, context and middleware; how a turn runs, the poll endpoint and its status line, Stop, Retry, long chats, approvals, side agents and classification, models, failover |
| [Tables and charts](tables-and-charts.md) | `AgentResource`, the `Filter` vocabulary, `AgentResources`, `draw-chart`, page context |
| [MCP clients](mcp-clients.md) | Tokens, abilities, tool scopes and expiry, connecting Claude Code, Claude Desktop and Cursor, the endpoint's middleware |
| [Budgets and limits](budgets-and-limits.md) | The platform ceiling in config, the `agent_limits` rows, inheritance, `AgentBudget`, what each turn cost: the record and the log line |
| [Tenancy](tenancy.md) | Workspaces, the `{tenant}` path, workspace-bound tokens, per-workspace keys and limits, database-per-tenant migrations |
| [Configuration](configuration.md) | Every config key and environment variable, the `Agents` facade |
| [Security](security.md) | The threat model: trust boundaries, prompt injection, the prompt guard, redaction, what the package enforces and what stays yours |
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
