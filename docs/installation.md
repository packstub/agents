# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.4 or newer |
| Laravel | 13.x |
| laravel/ai | ^0.11 |
| laravel/mcp | ^0.9 |
| laravel/sanctum | ^4 (tokens for MCP clients) |

The package requires `laravel/ai`, `laravel/mcp` and `laravel/sanctum`, so Composer installs them for you. It has no front end of its own: the tools, the MCP server and its tokens, the turn job, budgets and limits run in any Laravel app.

**In a Filament panel**, install [packstub/filament-agents](https://packstub.dev/docs/filament-agents/installation) instead. It requires this package, registers it through `AgentsPlugin` in the panel provider, and adds the chat pages, the Agent access page and the operator pages. Everything below applies there too; only the registration differs.

## Install

```bash
composer require packstub/agents
php artisan packstub-agents:install
```

The install command publishes `config/packstub-agents.php`, offers to run the migrations and scaffolds `app/Ai/Agents/Assistant.php`. The migrations create the `agent_limits` table and the chat tables (`agent_conversations`, `agent_conversation_messages`, `agent_message_feedback`, `agent_conversation_summaries`, `agent_turns`). They run from the package by default; a database-per-tenant app publishes and splits them, see [Tenancy](tenancy.md).

## Register the agent and the tools

A service provider tells the package what it works with, through the `Agents` facade:

```php
use App\Ai\Agents\Assistant;
use App\Mcp\Servers\AcmeServer;
use Packstub\Agents\Facades\Agents;

public function boot(): void
{
    Agents::useAgent(Assistant::class);
    Agents::useServer(AcmeServer::class);          // or Agents::useTools([SearchOrders::class, ConfirmOrder::class])
    Agents::authorizeUsing(fn (string $ability) => auth()->user()->can($ability));
    Agents::roleLabelUsing(fn () => auth()->user()->role?->getLabel());
}
```

- `useAgent()` is your `Agent` subclass, see [The agent](assistant.md). Until you call it, the package's `DefaultAgent` answers with only the registered tools and a generic persona.
- `useServer()` names the `AgentServer` subclass with the tool list, see [Tools](tools.md); `config('packstub-agents.mcp.server')` does the same from config. Without either, the package's own server serves what `useTools()` was given, or its generic `draw-chart` tool alone.
- `authorizeUsing()` tells the package how to check an ability for the current person. Without it, an ability goes through Laravel's `Gate` when a gate of that name exists and is otherwise allowed, so an app without abilities works out of the box.
- `roleLabelUsing()` gives the prompt and the refusal messages a role name ("Your role (Viewer) is not allowed to do this").
- `useResources()`, `credentialsUsing()`, `useMiddleware()` and `limitsAuthorizeUsing()` are described in [Configuration](configuration.md#the-agents-facade).
- The assistant's name comes from config (`AGENT_NAME`).

The scaffold commands print the same registration: `packstub-agents:agent` and `packstub-agents:tool` end with the facade call to add.

## Who is acting and where

Every part of the package that needs the person, the guard, the workspace or the locale reads it from `Packstub\Agents\Contracts\AgentContext`, bound in the container as `Packstub\Agents\Support\Context\LaravelContext`:

- **The person** is whoever the guard in use holds — the default guard, or the one an auth middleware picked (`auth:sanctum` on the MCP endpoint). A turn records the guard it was asked on and the worker signs the person in on that same guard.
- **The workspace** is what `Agents::tenantUsing()` resolves; unregistered, the app is one workspace.
- **The locale** is the app's.

### Workspaces

Tell the package what a workspace is and how the current one is found:

```php
use App\Models\Team;
use Packstub\Agents\Facades\Agents;

Agents::tenantModel(Team::class, slugAttribute: 'slug');
Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);

// Optional: what a database switch needs when a worker or an MCP request enters a workspace.
Agents::enteringTenant(function (Team $team): Closure {
    tenancy()->initialize($team);

    return fn () => tenancy()->end();
});
```

- `tenantModel()` is the model a worker finds a workspace by (its key is stored on the turn) and the MCP path names it by (`slug`, or the key when null).
- `tenantUsing()` resolves the current workspace of a request: budgets, limits, the prompt's workspace line and a workspace's own provider key (`credentialsUsing()`) are keyed by it.
- `enteringTenant()` runs when a queue worker or an MCP request *enters* a workspace that was found by key or slug — the place for a database switch or whatever your tenancy layer needs. Whatever it returns runs when the worker leaves the workspace again, so a long-lived worker does not stay on the last workspace's connection; return nothing when there is nothing to undo.
- Membership goes through your user model's `canAccessTenant(Model $tenant): bool` when it has one; without it every signed-in person may enter every workspace, so add the method as soon as you have more than one.

Put `{tenant}` in the MCP path (`'mcp/{tenant}'`) and a token is bound to the workspace it was minted for: see [Tenancy](tenancy.md).

## A queue worker

Every answer is produced in a queued job (`Packstub\Agents\Jobs\RunAgentTurn`), so no request holds a connection open while the model works and an answer keeps coming after the request that asked for it ended. Run a worker as you would for any queued job (`php artisan queue:work`, Horizon, Laravel Cloud's workers); `chat.queue_connection` and `chat.queue` pick where the jobs go.

A turn that sits on the queue for `chat.worker_wait` seconds (10) without a worker taking it says so on the status line, with the command to run, instead of "Thinking…" until the job timeout.

No worker? Set `chat.driver` to `sync` (`AGENT_TURN_DRIVER=sync`) and the job runs inside the request that asked, whatever the app's queue connection is — everything else the same, except that an answer dies with the request. See [The agent](assistant.md#how-a-turn-runs).

## Sanctum

MCP clients authenticate with Sanctum personal access tokens, so your user model needs the `HasApiTokens` trait and the `personal_access_tokens` table:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
}
```

```bash
php artisan vendor:publish --tag=sanctum-migrations
php artisan migrate
```

Skip this when you only run turns from your own app and set `AGENT_MCP_ENABLED=false`.

## Routes

| Route | Where | Middleware |
| --- | --- | --- |
| `POST {mcp.path}` | the MCP endpoint | `mcp.middleware` (`throttle:60,1`, `auth:sanctum`, `AuthenticateAgent`) |
| `GET {chat.path}/chat/{conversation}/turn` | what a chat polls while an answer is produced: the answer so far, rendered, and a version stamp | `chat.middleware` (`['web', 'auth']`) |

`chat.path` defaults to `agents`. The poll endpoint answers for the conversation's own participant; it is left out when the Filament plugin registered its own on the panel.

## The provider key

Provider credentials live in laravel/ai's `config/ai.php`, so the usual environment variables work:

```dotenv
AGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-…
```

or

```dotenv
AGENT_PROVIDER=openai
OPENAI_API_KEY=sk-…
```

or

```dotenv
AGENT_PROVIDER=gemini
GEMINI_API_KEY=AIza…
```

or

```dotenv
AGENT_PROVIDER=xai
XAI_API_KEY=xai-…
```

Those four have model entries out of the box (`auto`, `fast` and `deep`, named by model, see [Configuration](configuration.md#models)). Any other laravel/ai text provider works too — `AGENT_PROVIDER=ollama` for a local model, `openrouter`, `mistral`, `groq`, `deepseek` — with its key in `config/ai.php`; the catalog then offers the provider's smartest and cheapest models, by name.

Without a key `AgentModels::enabled()` is false — a chat surface hides itself — and the MCP endpoint keeps answering, since it does not need a model. `AGENT_ENABLED=false` switches the agent off regardless.

## Write a first tool

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
```

Add it to the server's `$tools`, mint a token and connect a client. The next page, [Tools](tools.md), explains what goes into a tool.
