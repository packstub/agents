# Budgets and limits

An agent that calls a frontier model on every question needs a ceiling. The package counts what laravel/ai already stores with every assistant message (the provider's token usage) and refuses a turn before it reaches the provider when a limit is hit. The provider's own spend limit stays the real backstop.

## The limits

| Limit | Scope | Config key / env |
| --- | --- | --- |
| Questions per minute | per user | `turns_per_minute` / `AGENT_TURNS_PER_MINUTE` (6) |
| Answers per day | per workspace | `turns_per_day` / `AGENT_TURNS_PER_DAY` (150) |
| Tokens per day | per workspace, all token kinds | `tokens_per_day` / `AGENT_TOKENS_PER_DAY` (600,000) |
| Tokens per month | per workspace, all token kinds | `tokens_per_month` / `AGENT_TOKENS_PER_MONTH` (3,000,000) |
| Tokens per day | per user, inside the workspace | `user_tokens_per_day` / `AGENT_USER_TOKENS_PER_DAY` (100,000) |
| Tokens per month | per user, inside the workspace | `user_tokens_per_month` / `AGENT_USER_TOKENS_PER_MONTH` (1,500,000) |
| Max question length | characters | `prompt_max_chars` / `AGENT_PROMPT_MAX_CHARS` (2,000) |

`null` (or `0` in the environment) disables a limit. The values in `config/packstub-agents.php` are the platform's ceiling; the rows below override them.

When a turn is refused nothing is sent to the provider. The question stays in the conversation and the turn ends `refused` with the reason ("This workspace reached today's limit of 150 answers. It resets at midnight."), so nothing typed is lost and it can be sent again once the limit allows. The `EnforceBudget` [middleware](assistant.md#middleware) makes the check when the turn runs, and counts it, so the limits hold for every turn however it was started — a follow-up that waited in the queue, a console command, an app that prompts the agent directly.

## The agent_limits rows

Rows in the `agent_limits` table (`Packstub\Agents\Models\AgentLimit`) override the config with three scopes:

| Scope | Applies to | Fields |
| --- | --- | --- |
| `global` | every workspace and user | all of them, plus `enabled` |
| `tenant` (`tenant_id`) | one workspace | all of them |
| `user` (`user_id`) | one account, in every workspace | the per-user fields: `enabled`, `turns_per_minute`, `user_tokens_per_day`, `user_tokens_per_month`, `prompt_max_chars` |

```php
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentLimits;

AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 300]);
AgentLimit::query()->create(['scope' => 'tenant', 'tenant_id' => $team->id, 'tokens_per_month' => 10_000_000, 'note' => 'Enterprise plan']);
AgentLimit::query()->create(['scope' => 'user', 'user_id' => $user->id, 'enabled' => false]);
AgentLimits::flush();
```

Empty fields inherit: user → workspace → everyone → the config defaults. `enabled` on a workspace row switches the agent off for that workspace entirely (`AgentModels::enabled()` turns false); on a user row it does the same for one person. Rows live on `packstub-agents.limits_connection` (`AGENT_LIMITS_CONNECTION`), the central connection in a database-per-tenant app, since limits are the operator's, not the workspace's. Resolved limits are cached for the request; call `AgentLimits::flush()` after an edit. `Agents::limitsAuthorizeUsing()` and `Agents::canManageLimits()` are the hook and the check for who may edit them in your own admin.

**In a Filament panel**, the operator's AI limits resource of [Filament Agents](https://packstub.dev/docs/filament-agents/budgets-and-limits) edits these rows, and its AI turns page lists the records below.

## In code

```php
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;

AgentLimits::effective();          // the merged limits for the current tenant and user
AgentLimits::effective($tenant, $user);
AgentBudget::refusal($prompt);     // the reason a turn may not start, or null
AgentBudget::summary();            // turns today, tokens this month, per-user counters and their limits
```

`AgentBudget::summary()` is what a workspace settings page shows next to "your AI usage this month". Every counter comes from `agent_conversation_messages`, so no extra bookkeeping is needed.

A guard rail of your own (a plan without the assistant, a frozen workspace) is a [middleware](assistant.md#middleware) that throws `TurnRefused` with the message the person should read.

## What each turn cost

Every turn leaves a record on its `agent_turns` row (`Packstub\Agents\Models\AgentTurn`) when it ends: the provider and model that answered, the token usage the provider reported (prompt, completion, cache reads and writes, reasoning), the tools called in order, the wall time, and how it ended — `stop`, `length`, `content_filter` or `dropped` from the provider, `stopped` by the person, `refused` by a middleware, `failed` by an error or a lost worker. Query the model for a usage page, a cost per team or an alert.

Ended turns are kept for `chat.keep_turns_days` (`AGENT_KEEP_TURNS_DAYS`, 90; `null` keeps them forever) and pruned by Laravel's model pruning — add the model to your schedule:

```php
Schedule::command('model:prune', ['--model' => [\Packstub\Agents\Models\AgentTurn::class]])->daily();
```

The rows live with the conversations (`ai.conversations.connection`); in a database-per-tenant app that is the tenant database.

### The log line

Set `log.channel` (`AGENT_LOG_CHANNEL`) to a channel from `config/logging.php` and every ended turn writes one info line there — `Agent turn done: anthropic/claude-opus-5, 1,240 tokens in, 310 out, 2 tool calls, 4.2 s, ended stop` — with the whole record in the context (`turn`, `conversation`, `user`, `tenant`, `panel`, `status`, `provider`, `model`, `model_key`, the five token counts, `tool_calls`, `duration_ms`, `finish_reason`, `error`). Point it at a JSON channel for your log platform, or at `stack` to keep it with the app log. `null` (the default) logs nothing; the row carries the record either way.

For anything beyond that — an audit trail with the prompt, redaction, a plan check — write a [middleware](assistant.md#middleware) and read the response in its `then()` callback.
