# Configuration

`php artisan packstub-agents:install` publishes `config/packstub-agents.php`. What the app registers in code goes through the `Agents` facade (below); config is what every runtime (queue, console, MCP requests) reads, so anything a panel plugin sets fluently is mirrored into it.

## config/packstub-agents.php

| Key | Default | Env | What it does |
| --- | --- | --- | --- |
| `name` | `Assistant` | `AGENT_NAME` | how the assistant introduces itself |
| `panel` | `null` | | the panel the assistant lives in; set by the Filament plugin when it registers, `null` otherwise |
| `provider` | `anthropic` | `AGENT_PROVIDER` | `anthropic`, `openai`, `gemini` or `xai` have picker entries; any other laravel/ai text provider (`ollama`, `openrouter`, `mistral`, `groq`, `deepseek`…) runs on its smartest and cheapest models. The platform default; a workspace may bring its own |
| `failover` | `[]` | `AGENT_FAILOVER` | providers to fall back to, in order (`gemini,openai`), when the platform provider refuses a turn before it started answering; see [Failover](assistant.md#failover) |
| `enabled` | `null` | `AGENT_ENABLED` | `null` = enabled when a key exists for the provider in use or for the provider of any catalog entry; `false` switches the agent off (the MCP endpoint stays) |
| `models` | see below | `AGENT_MODEL`, `AGENT_MODEL_FAST`, `AGENT_MODEL_DEEP` | the model catalog per provider: model, effort, an optional label (the model's name otherwise) and optionally the provider the entry runs on |
| `max_steps` | `12` | | tool round-trips one turn may take before the agent has to answer |
| `max_tokens` | `4096` | | answer length |
| `max_conversation_messages` | `40` | | a second ceiling on the history window beside `history.max_tokens`: at most this many earlier rows are replayed, however many fit the token budget |
| `middleware` | `[]` | | your own agent middleware, run on every turn after the package's guard rails; see [Middleware](assistant.md#middleware) |
| `history.max_tokens` | `24000` | `AGENT_HISTORY_MAX_TOKENS` | the history window, in estimated tokens; what no longer fits is folded into a rolling summary the model reads first |
| `history.keep_tool_results_turns` | `3` | | tool results older than this many turns are replaced by a one-line placeholder when replayed |
| `history.notice_share` | `0.7` | | from this share of the window a chat surface suggests continuing in a new chat |
| `history.meter_share` | `0.25` | | from this share of the window a chat surface shows a context meter |
| `history.compress_keep_turns` | `2` | | how many of the latest exchanges `AgentConversationStore::compactNow()` keeps verbatim while it folds the rest into the rolling summary |
| `chat.driver` | `queue` | `AGENT_TURN_DRIVER` | how a turn runs: `queue` hands the job to a worker, `sync` runs it inside the request (no worker; an answer ends with the request that asked) |
| `chat.queue_connection` | `null` | `AGENT_QUEUE_CONNECTION` | the queue connection the turn job runs on with the `queue` driver; `null` = the app's default |
| `chat.queue` | `null` | `AGENT_QUEUE` | the queue name; `null` = the connection's default |
| `chat.job_timeout` | `600` | `AGENT_JOB_TIMEOUT` | how long one turn may run on the worker, in seconds; a turn whose job went quiet for longer is marked failed |
| `chat.worker_wait` | `10` | `AGENT_WORKER_WAIT` | how long a turn may wait for a worker before the status line says none has taken it, in seconds (the queue driver only) |
| `chat.poll_interval` | `600` | `AGENT_POLL_INTERVAL` | how often a chat surface asks for the answer so far while a turn runs, in milliseconds |
| `chat.path` | `agents` | | where the poll endpoint lives: `GET {path}/chat/{conversation}/turn`, see [Routes](installation.md#routes) |
| `chat.middleware` | `['web', 'auth']` | | the middleware of that endpoint; the Filament plugin registers its own on the panel's routes instead |
| `chat.keep_turns_days` | `90` | `AGENT_KEEP_TURNS_DAYS` | how long ended turns (the per-turn record) are kept; `null` keeps them; pruned by `model:prune --model=Packstub\Agents\Models\AgentTurn` |
| `log.channel` | `null` | `AGENT_LOG_CHANNEL` | the log channel that gets one line per ended turn (provider, model, tokens, tools, duration, how it ended); `null` logs nothing. See [What each turn cost](budgets-and-limits.md#what-each-turn-cost) |
| `limits.*` | see [Budgets and limits](budgets-and-limits.md) | `AGENT_TURNS_PER_MINUTE`, `AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`, `AGENT_USER_TOKENS_PER_DAY`, `AGENT_USER_TOKENS_PER_MONTH`, `AGENT_PROMPT_MAX_CHARS` | the platform ceiling |
| `limits_connection` | `null` | `AGENT_LIMITS_CONNECTION` | the connection of the `agent_limits` table (the central one in a database-per-tenant app) |
| `mcp.enabled` | `true` | `AGENT_MCP_ENABLED` | the MCP endpoint |
| `mcp.path` | `mcp` | | the endpoint path; `mcp/{tenant}` with workspaces |
| `mcp.server` | `null` | | an `AgentServer` subclass; `null` = the package's server with the tools given to `Agents::useTools()` |
| `mcp.middleware` | `['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class]` | | the endpoint's middleware |
| `run_migrations` | `true` | | run the package migrations from the vendor directory; `false` to publish and split them |

The provider keys themselves live in laravel/ai's `config/ai.php` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `XAI_API_KEY`, and so on for the other providers).

### models

```php
'models' => [
    'anthropic' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'claude-opus-5'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'claude-haiku-4-5'), 'effort' => null],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'claude-opus-5'), 'effort' => 'xhigh'],
        // 'flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'],
        // 'local' => ['label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.3', 'effort' => null, 'failover' => []],
    ],
    'openai' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP'), 'effort' => 'high'],
    ],
    'gemini' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'gemini-3.8-flash'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'gemini-3.5-flash-lite'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'gemini-3.8-flash'), 'effort' => 'high'],
    ],
    'xai' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'grok-4.6'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'grok-4.6'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'grok-4.6'), 'effort' => 'xhigh'],
    ],
],
```

Rename, remove or add entries; a picker shows whatever is there. A `null` label names the entry after the model it runs — Claude Opus 5, Claude Haiku 4.5 — and a second unlabelled entry on the same model adds its key to tell them apart (Claude Opus 5 · Deep); set a label to show something else (`'label' => 'Fast'`). A `null` model resolves to the provider's smartest model (or cheapest for the `fast` key), and the entry is named after the model that resolves. A provider with no entries at all (Ollama, OpenRouter, Mistral, Groq, DeepSeek…) gets its smartest (`auto`) and cheapest (`fast`) models with no effort; add an entry to pin models or to offer a `deep` one. Effort is passed as Anthropic's `output_config.effort`, OpenAI's and xAI's `reasoning.effort` (reasoning models only) or Gemini's thinking level (`low`, `medium`, `high`; `xhigh` is sent as `high`). When you pin a model that rejects the parameter, set its effort to `null`.

The catalog is the list of the provider in use (`provider`, or the workspace's own). An entry in that list may name another provider to run on — `'provider' => 'gemini'` on the commented `flash` entry above puts Gemini Flash next to Claude on an Anthropic install. Such an entry is listed only when its provider has a key in `config/ai.php`; when the catalog holds entries of more than one provider, `AgentModels::groups()` groups them under provider headings, the catalog's own provider first. Its model and effort are in that provider's terms (a Gemini thinking level on a Gemini entry), and it fails over down the `failover` list like any entry, with its own provider left out — the platform provider included when it is listed. Give an entry its own `failover` list to override the global one: `[]` keeps a local Ollama model local, for data that must not leave the server. A workspace on its own key sees only the entries of its provider; see [Tenancy](tenancy.md#a-workspaces-own-key). The agent is on when the provider in use has a key, or when any listed entry's provider has one.

## The Agents facade

`Packstub\Agents\Facades\Agents` is how the app registers itself, from a service provider's `boot()`:

```php
use Packstub\Agents\Facades\Agents;

Agents::useAgent(Assistant::class);
Agents::useServer(AcmeServer::class);
Agents::useTools([SearchOrders::class, ConfirmOrder::class]);
Agents::useResources([Orders::class, Customers::class]);
Agents::useMiddleware([AuditTurns::class]);
Agents::authorizeUsing(fn (string $ability): bool => auth()->user()->can($ability));
Agents::roleLabelUsing(fn (): ?string => auth()->user()->role?->getLabel());
Agents::credentialsUsing(fn (): ?WorkspaceCredentials => ...);
Agents::limitsAuthorizeUsing(fn (): bool => auth()->user()->is_admin);
Agents::tenantModel(Team::class, slugAttribute: 'slug');
Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);
Agents::enteringTenant(fn (Team $team): ?Closure => ...);
```

| Method | |
| --- | --- |
| `useAgent(class)` | your `Agent` subclass (default: the package's `DefaultAgent`) |
| `useServer(class)` | the `AgentServer` subclass with the tool list, name and instructions |
| `useTools(array)` | the tool list when there is no server class |
| `addTools(array\|Closure)` | tools appended to the server's own list (what the Filament plugin uses for `show-table`) |
| `useResources(array)` | the `AgentResource` classes for filters, summaries and page context |
| `useMiddleware(array)` | your own agent middleware — classes with `handle(AgentPrompt $prompt, Closure $next)`, instances or closures — run on every turn after the package's guard rails, after the ones in config; see [Middleware](assistant.md#middleware) |
| `authorizeUsing(fn (string $ability): bool)` | how a tool's ability is checked for the current person (default: the `Gate` when it has that ability, otherwise allowed) |
| `roleLabelUsing(fn (): ?string)` | the person's role label for the prompt and refusals |
| `credentialsUsing(fn (): ?WorkspaceCredentials)` | where a workspace's own provider, key and model come from |
| `limitsAuthorizeUsing(fn (): bool)` | who may edit the `agent_limits` rows; read back with `canManageLimits()` |
| `tenantModel(class, ?slugAttribute)` | the workspace model, and the attribute the MCP path names it by |
| `tenantUsing(fn (): ?Model)` | how the current workspace is found; unregistered, the app is one workspace |
| `enteringTenant(fn (Model $tenant): ?Closure)` | what a worker or an MCP request does on entering a workspace; the returned closure runs on leaving |

It also reads back what the app told the package: `name()`, `tenant()`, `inPanel()`, `toolClasses()`, `agentClass()`, `serverClass()`, `resourceClasses()`, `registeredResources()` (the resources with their keys), `middleware()`, `allows($ability)`, `roleLabel()`, `credentials()`, `canManageLimits()`, `tenantModelClass()`, `tenantSlugAttribute()`, `tenantResolver()`, `tenantEnterHook()`, and `context()` — the `AgentContext` that knows who is acting and where (`Support\Context\LaravelContext`, or the Filament plugin's `FilamentContext` in a panel). Tools use it; your own code may too. `agent($pageContext, $modelKey)` builds the configured agent for a turn, the page context and the picker key applied. `agentAccess()`, `agentAccessAbility()`, `agentAccessGroup()`, `hideAskButtonOn()` and `askButtonHiddenOn()` live on the same facade but are read by the Filament plugin only.

**In a Filament panel**, `AgentsPlugin::make()` has a fluent method for each of these and adds the pages; see [Filament Agents](https://packstub.dev/docs/filament-agents/configuration).

## Translations

Strings are `__()` calls keyed by the English text, with JSON files for German, Spanish, Romanian and Russian in `resources/lang`. Add your own language by publishing a JSON file with the same keys into your app's `lang/` directory.
