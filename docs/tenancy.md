# Tenancy

The package works in an app without tenancy: one workspace, the app's name, everyone's conversations in one table. With workspaces it follows the current one: the prompt names it, the budget counts it, the MCP endpoint carries it and tokens are bound to it.

## Workspaces

Tell the package what a workspace is and how the current one is found (see [Installation](installation.md#workspaces) for each hook):

```php
use App\Models\Team;
use Packstub\Agents\Facades\Agents;

Agents::tenantModel(Team::class, slugAttribute: 'slug');
Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);
Agents::enteringTenant(function (Team $team): Closure {
    tenancy()->initialize($team);

    return fn () => tenancy()->end();
});
```

A queue worker running a turn and an MCP request both *enter* the workspace the turn or the path names: the model is found by key or slug, the person's `canAccessTenant()` is checked, and `enteringTenant()` runs (its return value runs on leaving), so a database switch or a scope happens before any tool does.

**In a Filament panel**, the panel's tenant is the workspace, Filament's `TenantSet` event plays the part of `enteringTenant()`, and [Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) switches the database on it; see [Filament Agents](https://packstub.dev/docs/filament-agents/tenancy).

## The MCP path

Put `{tenant}` in the path so an external agent works inside one workspace:

```php
// config/packstub-agents.php
'mcp' => [
    'path' => 'mcp/{tenant}',
],
```

`AuthenticateAgent` then, after `auth:sanctum`:

1. looks the workspace up by the slug attribute of `tenantModel()` (or the key);
2. checks the person's membership with `canAccessTenant()` on the user model (404 otherwise);
3. checks that the token carries `tenant:{slug}` (403 when it was issued for another workspace, even when the person is a member there);
4. signs the token's user in on the request's guard and enters the workspace.

Tools do not need to know they run over MCP. Mint the token with the workspace ability for that URL:

```php
$user->createToken('acme', ['read', 'tenant:'.$team->slug], now()->addDays(30));
```

## A workspace's own key

A workspace can bring its own provider, key and preferred model. Tell the package where they come from:

```php
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Facades\Agents;

Agents::credentialsUsing(fn () => ($team = Agents::tenant())
    ? new WorkspaceCredentials(
        provider: $team->settings->assistant_provider,   // 'anthropic' | 'openai' | 'gemini' | 'xai' | any laravel/ai text provider
        apiKey: $team->settings->assistant_api_key,
        model: $team->settings->assistant_model,         // a model key: 'auto' | 'fast' | 'deep'
    )
    : null);
```

When the callback returns credentials with a key, the turn runs on that provider with that key (the key is swapped into laravel/ai's config for the request), and the agent is enabled for that workspace even when the platform has no key. Return `null` for "the platform's provider". The catalog for the workspace is that provider's list without any entry that names another provider (see [models](configuration.md#models)): such an entry would run on the platform's key and silently change who pays. A workspace on the platform key sees every entry whose provider has a platform key.

## Per-workspace limits

An `agent_limits` row with the `tenant` scope overrides the global one for that workspace; `enabled` on it switches the agent off for the workspace. See [Budgets and limits](budgets-and-limits.md).

## Database per tenant

By default the package runs its migrations from the vendor directory. In a database-per-tenant app, the tables belong to different databases:

| Table | Where | Why |
| --- | --- | --- |
| `agent_limits` | central | limits are the operator's |
| `agent_conversations`, `agent_conversation_messages`, `agent_message_feedback`, `agent_conversation_summaries`, `agent_turns` | tenant | one workspace never sees another's conversations, and an export or restore carries them along; the turn job reads its row on the workspace's connection |

So:

```php
// config/packstub-agents.php
'run_migrations' => false,
'limits_connection' => env('AGENT_LIMITS_CONNECTION', 'central'),
```

```bash
php artisan vendor:publish --tag=packstub-agents-migrations
```

Keep `create_agent_limits_table` with your central migrations and move `create_agent_chat_tables`, `create_agent_conversation_summaries_table` and `create_agent_turns_table` next to your tenant migrations. `AgentLimit` reads `limits_connection`, so limits are edited from the central side; `agent_turns` is then a tenant table, read inside the workspace.

## What follows the workspace

| | Without tenancy | With tenancy |
| --- | --- | --- |
| Prompt | "Workspace: {app name}" | "Workspace: {tenant name}" |
| Per-minute rate limit key | `central` + user | tenant key + user |
| Turns per day, tokens per month | the whole app | the tenant's database (with database per tenant) |
| Tokens | `read` / `write` | `read` / `write` / `tenant:{slug}` |
| A queued turn | runs as the person | enters the workspace first, leaves it after |
