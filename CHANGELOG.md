# Changelog

All notable changes to `packstub/agents` are documented here.

## 1.2.0 — 2026-09-10

### Added

- **Starter questions.** `Agent::suggestions()` returns the questions an empty chat offers as one-click prompts, in the person's language: by default what needs attention today, the latest records of the first two agent resources and what the assistant can do, or, when the chat was opened from a record, two questions about that record. An app returns its own from the domain ("Which orders are waiting for a phone call?"). Filament Agents 1.9 shows them on a new chat.

### Fixed

- **Page context without a panel.** `PageContext::resolve('widgets/12')` resolved the record through a Filament resource method, so a headless `AgentResource` (one registered with `Agents::useResources()` in a plain app) raised an error instead of a label; it now falls back to the resource's `getEloquentQuery()` or its model.

## 1.1.0 — 2026-09-10

### Added

- **A proposed call as a question.** `AgentTool::describe(array $arguments): ?string` lets a write tool phrase its own calls ("Confirm order RO-00016 for Acme?"); `ApprovableTool::question($tool, $arguments)` returns that sentence, or the tool's title and the first scalar argument when the tool has no `describe()`, and `ApprovableTool` passes it as the approval's reason, so the pending approval stored by laravel/ai carries the sentence a client shows. Filament Agents 1.8 renders the proposal with it.
- **A missing worker is named.** A turn handed to the queue that no worker takes within `chat.worker_wait` seconds (`AGENT_WORKER_WAIT`, 10) gets a status line that says so, with the command to run or the sync driver to set, instead of "Thinking…" until `chat.job_timeout`. `AgentTurns::statusText($turn)` gives a chat surface the line the poll endpoint returns; `awaitingWorker($turn)` the bare check.

## 1.0.0 — 2026-09-09

The engine of [packstub/filament-agents](https://github.com/packstub/filament-agents) 1.6, extracted into its own package so a plain Laravel app can install it without Filament. Same `Packstub\Agents\` namespace, same `config/packstub-agents.php` and environment variables, same migration file names, same class names: a panel app installs `packstub/filament-agents` ^1.7, which requires this package, and has nothing to run.

### Added

- **One tool list for the agent and the MCP server.** `AgentTool` is a `laravel/mcp` tool with an `$ability`, a `run()` returning data for the model and domain errors mapped to tool errors; `AgentServer` carries the name, the instructions and the `$tools`; the app names them with `Agents::useServer()` or `Agents::useTools()`. The `packstub-agents:tool` and `packstub-agents:agent` scaffolds and the `packstub-agents:install` command print the service-provider registration, or the plugin call when the Filament layer is installed.

- **Writes are proposals.** A tool without `#[IsReadOnly]` is wrapped as an `ApprovableTool` for the agent, so laravel/ai pauses the turn until the person approves or rejects it; over MCP it needs a write token and then runs directly with the person's role.

- **MCP over HTTP.** `POST /mcp` behind `throttle`, `auth:sanctum` and `AuthenticateAgent`, registered whenever `mcp.enabled` is on (the package's own server until the app names one). Sanctum tokens carry `read` / `write` abilities, `tool:{name}` scopes that limit a token to named tools, `tenant:{slug}` that binds it to a workspace, and an optional expiry; a read token cannot run write tools, a scoped token sees only its tools. `AgentTool::tokenRefusal()`, `accessToken()`, `tokenTools()` and `tokenIsScoped()` expose the checks to the app's own tools.

- **Queued turns with a record.** `AgentTurns::enqueue()` and the `RunAgentTurn` job produce an answer in a worker (or inside the request with `chat.driver` = `sync`), stream it into an `agent_turns` row, honour Stop, run follow-ups in order per conversation, and keep the record when the turn ends: provider and model, tokens (prompt, completion, cache reads and writes, reasoning), tools called, duration and how it ended. `GET {chat.path}/chat/{conversation}/turn` under `chat.middleware` reads the answer so far. `log.channel` writes one line per ended turn; `chat.keep_turns_days` prunes them with `model:prune`.

- **Who is acting and where.** `Contracts\AgentContext`, bound as `Support\Context\LaravelContext`: the person on the guard in use, the workspace from `Agents::tenantUsing()`, membership through the user's `canAccessTenant()`, the workspace model and slug from `Agents::tenantModel()`, and `Agents::enteringTenant()` for what a database switch needs when a worker or an MCP request enters a workspace — what it returns runs on leaving. A turn records the guard it was asked on and the worker signs the person in on it.

- **The base `Agent`.** `persona()` and `domain()` slots on top of generic working and answering rules, a dynamic block (date, workspace, person, role, language, page context) prepended to the question by the `AttachContext` middleware so the system prompt and the settled history stay cacheable, Anthropic cache breakpoints, reasoning effort or thinking level per model, and a middleware pipeline: `EnforceBudget` first, the app's own classes after it (`Agents::useMiddleware()` or config `middleware`), `TurnRefused` to stop a turn with a message.

- **Providers, models and failover.** A `models` catalog per provider (Anthropic, OpenAI, Gemini, xAI out of the box, entries named after their model; any other laravel/ai text provider on its smartest and cheapest models), entries that run on another provider, `AgentModels::resolve()` with the ordered provider list a turn runs on, and `failover` (`AGENT_FAILOVER`) to move to the next provider when the first refuses a turn before answering, with `AgentFailedOver` fired and the answering provider recorded.

- **Long conversations.** `AgentConversationStore` replays a token-budgeted window (`history.max_tokens`), replaces old tool results with a placeholder, folds what falls out into a rolling summary in `agent_conversation_summaries` extended in place, and offers `compactNow()` and continue-in-a-new-chat.

- **Budgets and limits.** Questions per minute per user, answers and tokens per day and per month per workspace, tokens per day and per month per user, and a prompt length cap, from `config/packstub-agents.php` and overridden by `agent_limits` rows (global, per workspace, per user; empty fields inherit) on `limits_connection`. `AgentBudget::refusal()` and `summary()`, `AgentLimits::effective()`.

- **Filters and summaries.** `Contracts\AgentResource` with `agentKey()`, `agentSummary()`, `agentContextLabel()` and `agentFilters()`, the `Filter` vocabulary (text, enum, boolean, flag, date, number) with its JSON schema, normalisation and query closure, `AgentResources` to share it with the app's search tools, `PageContext` for the record a chat was opened from, and the generic `draw-chart` tool; `Concerns\InteractsWithAgent` gives a Filament resource the defaults.

- **Tenancy.** `mcp/{tenant}` in the MCP path resolves the workspace by slug, checks membership and the token's `tenant:{slug}` ability and enters it before any tool runs; `credentialsUsing()` lets a workspace bring its own provider, key and model; `run_migrations` and `limits_connection` for database-per-tenant apps.
