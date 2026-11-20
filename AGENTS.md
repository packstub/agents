# packstub/agents

The agent engine for Laravel: an agent (laravel/ai) and an MCP server (laravel/mcp) sharing one tool list, and the chat itself (Blade, HTTP and Alpine), runnable without Filament or Livewire. **Agents for Laravel** on packstub.dev. `packstub/filament-agents` (the sibling repo, `plugins/filament-agents`) adds the panel side (the chat page as a shell around this package's chat, the operator pages) and requires this package; its classes are `Packstub\FilamentAgents\…` since 1.20, a root of its own.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, no Filament: the app registers through the facade)
composer test:filter <name>
composer lint               # Pint
npm run build               # resources/dist from resources/js and resources/css (esbuild); the built files are committed
```

## Layout

- `src/Ai` — the base `Agent` (persona/domain slots, generic rules, provider options, `HasMiddleware`), `ApprovableTool`, `WorkspaceCredentials`; `Ai/Middleware/EnforceBudget` and the app's own middleware run on every turn, `AttachContext` prepends the dynamic block, `Exceptions/TurnRefused` stops a turn with a message.
- `src/Ai/Side` — the structured-output side agents (`SideAgent`, `TitleAgent`, `SummaryAgent`, `ClassifierAgent`, `GuardAgent`): housekeeping calls on the cheapest model, each faked on its own in tests. `Ai/Middleware/GuardPrompt` is the prompt guard, `SupportedProviderTools` cuts web search and file search to what the step's provider runs.
- `src/Support/AgentRedactor` redacts answers (streaming snapshots included) and stored tool results; `src/Support/KnowledgeBase` + `Mcp/Tools/SearchKnowledgeBase` + `Commands/EmbedCommand` are the knowledge base; `Models/ConversationClassification` is a chat's topic, sentiment and resolved flag. All five (prompt_guard, redact, classify, web_search, knowledge_base) are opt-in config.
- `src/Jobs/RunAgentTurn` produces an answer and writes progress to `agent_turns`; `src/Support/AgentTurns` queues, polls, stops and records turns; `src/Http/Controllers/TurnController` is the poll endpoint; `src/Support/AgentRuntime` captures and restores who is acting and where in the worker.
- `src/Chat` — the chat's UI without Livewire: `ChatView` (what the views read; `open()` for a page, `make()` for every render after it), `ChatHost` (what the place it is rendered in supplies: URLs, the "@" search, the UI kit, embed views; `Hosts/RouteHost` is the plain app's, the plugin binds the panel's), `ChatParts` (`@agentsPart`: keyed parts with a revision, a stub for one the page already shows), `ChatRoutes`, `ChatAssets`, `ChatIcons`, `Components/{Chat,Chats}` (`<x-agents-chat />`, `<x-agents-chats />`). `Http/Controllers/ChatController` is what the page calls (`act/{action}`, `view`, `mentions`, `export`) and the optional pages.
- `resources/views/chat` (`packstub-agents-chat::`) — the chat's views, one set for the plain app and the panel. Buttons, links, icons and dropdowns go through `<x-dynamic-component :component="$ui.'::button'">`: `$ui` is `packstub-agents-chat` (the components in `chat/components`, same names and props as Filament's) or `filament`. No Tailwind utilities in these views: only `fi-chat-*` / `fi-agent-*` classes, styled by `resources/css/agents-chat.css` (colours through `--ac-*`, which fall back to `--agents-*`, then Filament's variables, then a built-in palette).
- `resources/js` — `agent-chat.js` (the Alpine component: actions over `fetch`, the refresh morphed in with `@alpinejs/morph`, the stream and the poll), `agent-chart.js`, `standalone.js` (the plain page's entry, Alpine bundled); built to `resources/dist` by `bin/build.js`.
- `src/Contracts/AgentContext` — who is acting and where; `src/Support/Context/LaravelContext` is the default binding (the guard in use, `Agents::tenantUsing()`, `Agents::tenantModel()`, `Agents::enteringTenant()`); the Filament plugin rebinds its own context.
- `src/Support/AgentConversationStore` — history: token-budgeted window, pruned tool results, rolling summary (`Models/ConversationSummary`), continue-in-new-chat.
- `src/Mcp` — `AgentTool` (ability check, token gate — read/write, `tool:{name}` scope — error mapping), `AgentServer`, the generic `DrawChart` tool; `Http/Middleware/AuthenticateAgent` is the token check on the MCP route.
- `src/Filters`, `src/Contracts/AgentResource`, `src/Concerns/InteractsWithAgent`, `src/Support/AgentResources`, `src/Support/PageContext` — a resource's filter vocabulary and summaries; the list comes from `Agents::useResources()` (or the panel, with the plugin).
- `src/Support/{AgentBudget,AgentLimits,AgentModels}` — spending guard rails and provider/model resolution; `Models/AgentLimit` is the operator's row.
- `AgentsManager` + `Facades\Agents` (what the app told us), `AgentsServiceProvider` (config, migrations, the MCP route, the poll route, the scaffold commands), `config/packstub-agents.php`, `database/migrations`, `stubs/`.
- `resources/lang/*.json` — the strings the engine emits (tool refusals, turn statuses), keyed by the English text.
- `docs/` customer docs (synced on every push to `main` into the store under `agents/1.x`).

## Conventions

- PHP 8.3+. Every change needs a test and a `CHANGELOG.md` line.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- A changelog section is the GitHub release's summary, and the release workflow appends GitHub's list of merged pull requests under it, so keep it short and human: an optional lead sentence, then at most ~6 one-line bullets saying what a user gets or what changed, in plain words. Class and method names only where a user needs the name to use it, never lists of them; no internal detail (CI, tests, refactors, edge-case semantics). Always keep breaking changes (an `**Upgrading:**` line saying what to do), security or privacy fixes (say so), new minimum versions and migrations to run.
- Strings are `__()` keyed by the English text; keep `resources/lang/{de,es,ro,ru}.json` in sync.
- After a change in `resources/js` or `resources/css` run `npm run build` and commit `resources/dist`; in an app that consumes the package through a path repository run `php artisan filament:assets` to see it in a panel.
- A controller method on a chat route reads its route parameters by name (`$request->route('action')`): in a panel with tenancy the first parameter is the tenant.
- Nothing here may import from `Filament\`, `Livewire\` or the plugin's classes (`AgentsPlugin`, `Filament\*`, `Livewire\*`, `Mcp\Tools\ShowTable`); what needs a panel belongs in `packstub/filament-agents`.
- Config keys, env vars, migration file names and every FQCN are read by the plugin and must not change inside a major. The plugin has its own PSR-4 root since filament-agents 1.20 (`Packstub\FilamentAgents\`); nothing here names its classes except `Support\Installed::plugin()`, by string.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- After a change here run the plugin's suite too (`plugins/filament-agents`, `composer test`); apps that consume the package through a path repository (the store, the demo) run their own agent suites.
