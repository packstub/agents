# packstub/agents

The agent engine for Laravel: an agent (laravel/ai) and an MCP server (laravel/mcp) sharing one tool list, runnable without Filament. **Agents for Laravel** on packstub.dev. `packstub/filament-agents` (the sibling repo, `plugins/filament-agents`) adds the chat and the operator pages to a Filament panel and requires this package; the two share the `Packstub\Agents\` namespace (Composer merges the PSR-4 directories), so class names must not collide across them.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, no Filament: the app registers through the facade)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Ai` — the base `Agent` (persona/domain slots, generic rules, provider options, `HasMiddleware`), `ApprovableTool`, `WorkspaceCredentials`; `Ai/Middleware/EnforceBudget` and the app's own middleware run on every turn, `AttachContext` prepends the dynamic block, `Exceptions/TurnRefused` stops a turn with a message.
- `src/Jobs/RunAgentTurn` produces an answer and writes progress to `agent_turns`; `src/Support/AgentTurns` queues, polls, stops and records turns; `src/Http/Controllers/TurnController` is the poll endpoint; `src/Support/AgentRuntime` captures and restores who is acting and where in the worker.
- `src/Contracts/AgentContext` — who is acting and where; `src/Support/Context/LaravelContext` is the default binding (the guard in use, `Agents::tenantUsing()`, `Agents::tenantModel()`, `Agents::enteringTenant()`); the Filament plugin rebinds its own context.
- `src/Support/AgentConversationStore` — history: token-budgeted window, pruned tool results, rolling summary (`Models/ConversationSummary`), continue-in-new-chat.
- `src/Mcp` — `AgentTool` (ability check, token gate — read/write, `tool:{name}` scope — error mapping), `AgentServer`, the generic `DrawChart` tool; `Http/Middleware/AuthenticateAgent` is the token check on the MCP route.
- `src/Filters`, `src/Contracts/AgentResource`, `src/Concerns/InteractsWithAgent`, `src/Support/AgentResources`, `src/Support/PageContext` — a resource's filter vocabulary and summaries; the list comes from `Agents::useResources()` (or the panel, with the plugin).
- `src/Support/{AgentBudget,AgentLimits,AgentModels}` — spending guard rails and provider/model resolution; `Models/AgentLimit` is the operator's row.
- `AgentsManager` + `Facades\Agents` (what the app told us), `AgentsServiceProvider` (config, migrations, the MCP route, the poll route, the scaffold commands), `config/packstub-agents.php`, `database/migrations`, `stubs/`.
- `resources/lang/*.json` — the strings the engine emits (tool refusals, turn statuses), keyed by the English text.
- `docs/` customer docs (synced on every push to `main` into the store under `agents/1.x`).

## Conventions

- PHP 8.4+ (not 8.3). Every change needs a test and a `CHANGELOG.md` line.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- Strings are `__()` keyed by the English text; keep `resources/lang/{de,es,ro,ru}.json` in sync.
- Nothing here may import from `Filament\`, `Livewire\` or the plugin's classes (`AgentsPlugin`, `Filament\*`, `Livewire\*`, `Mcp\Tools\ShowTable`); what needs a panel belongs in `packstub/filament-agents`.
- Config keys, env vars, migration file names and every FQCN are shared with the plugin and must not change inside a major.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- After a change here run the plugin's suite too (`plugins/filament-agents`, `composer test`); apps that consume the package through a path repository (the store, the demo) run their own agent suites.
