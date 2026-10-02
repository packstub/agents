<?php

namespace Packstub\Agents;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Ai\DefaultAgent;
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\SearchKnowledgeBase;
use Packstub\Agents\Support\KnowledgeBase;
use ReflectionProperty;

/**
 * What the app told the package about itself: the agent class, the tool list,
 * how to authorize an ability, where a workspace's own provider key comes
 * from, and — without a panel — what a workspace is. AgentsPlugin fills it in
 * when the panel registers; a plain Laravel app calls the same methods from a
 * service provider. Everything else in the package reads it through the
 * Agents facade. Who is acting and where comes from the AgentContext bound
 * in the container (see context()).
 */
class AgentsManager
{
    /** @var class-string<Agent>|null */
    protected ?string $agent = null;

    /** @var class-string<AgentServer>|null */
    protected ?string $server = null;

    /** @var list<class-string<Tool>> */
    protected array $tools = [];

    /** @var list<class-string<Tool>>|Closure|null tools appended to the base server's own list (show-table in a panel with agent resources) */
    protected array|Closure|null $addedTools = null;

    /** @var list<class-string<AgentResource>> */
    protected array $resources = [];

    /** @var list<class-string|object|Closure> */
    protected array $middleware = [];

    protected ?Closure $authorize = null;

    protected ?Closure $roleLabel = null;

    protected ?Closure $credentials = null;

    protected ?Closure $limitsAuthorize = null;

    protected ?Closure $participantByEmail = null;

    protected ?Closure $redactor = null;

    protected ?KnowledgeBase $knowledgeBase = null;

    /** @var list<Closure> */
    protected array $toolResultMaps = [];

    protected ?Closure $tenantResolver = null;

    protected ?Closure $tenantEnter = null;

    /** @var class-string<Model>|null */
    protected ?string $tenantModel = null;

    protected ?string $tenantSlugAttribute = null;

    protected ?string $agentAccessAbility = null;

    protected Closure|string|null $agentAccessGroup = null;

    /** @var list<string> */
    protected array $askButtonHiddenOn = ['*.pages.chat', '*.pages.chat.*'];

    /** How the assistant is called in the panel ("Ask Acme"). */
    public function name(): string
    {
        return (string) config('packstub-agents.name', 'Assistant');
    }

    /** Who is acting and where: the context bound in the container (LaravelContext, or FilamentContext once AgentsPlugin registered). */
    public function context(): AgentContext
    {
        return app(AgentContext::class);
    }

    /** True when the request is served inside the assistant's panel (chat, hooks and agent access show up); never without one. */
    public function inPanel(): bool
    {
        return $this->context()->inPanel();
    }

    /** The workspace the request runs in (the panel's tenant, or what tenantUsing() resolves), or null in a single-workspace app. */
    public function tenant(): ?Model
    {
        return $this->context()->tenant();
    }

    /**
     * Without a panel: how the current workspace is found — fn (): ?Model, null meaning one workspace.
     * A panel's tenant comes from Filament instead.
     */
    public function tenantUsing(Closure $resolve): void
    {
        $this->tenantResolver = $resolve;
    }

    public function tenantResolver(): ?Closure
    {
        return $this->tenantResolver;
    }

    /**
     * Without a panel: what the app does when a queue worker or an MCP request enters a workspace it found by
     * key or slug — fn (Model $tenant): ?Closure, a database switch for instance. What it returns, if anything,
     * runs when the worker leaves the workspace again. In a panel Filament's TenantSet plays this role.
     */
    public function enteringTenant(Closure $enter): void
    {
        $this->tenantEnter = $enter;
    }

    public function tenantEnterHook(): ?Closure
    {
        return $this->tenantEnter;
    }

    /**
     * Without a panel: the workspace model and the attribute the MCP path names it by ("mcp/{tenant}"; null = the key),
     * so a worker and an MCP request can find a workspace again.
     *
     * @param  class-string<Model>  $model
     */
    public function tenantModel(string $model, ?string $slugAttribute = null): void
    {
        $this->tenantModel = $model;
        $this->tenantSlugAttribute = $slugAttribute;
    }

    /** @return class-string<Model>|null */
    public function tenantModelClass(): ?string
    {
        return $this->tenantModel;
    }

    public function tenantSlugAttribute(): ?string
    {
        return $this->tenantSlugAttribute;
    }

    /** @param  class-string<Agent>  $class */
    public function useAgent(string $class): void
    {
        $this->agent = $class;
    }

    /** @return class-string<Agent> */
    public function agentClass(): string
    {
        return $this->agent ?? DefaultAgent::class;
    }

    public function agent(?string $pageContext = null, ?string $modelKey = null): Agent
    {
        $class = $this->agentClass();

        return new $class($pageContext, $modelKey);
    }

    /** @param  class-string<AgentServer>  $class */
    public function useServer(string $class): void
    {
        $this->server = $class;
    }

    /** @return class-string<AgentServer> */
    public function serverClass(): string
    {
        return $this->server ?? config('packstub-agents.mcp.server') ?? AgentServer::class;
    }

    /** @param  list<class-string<Tool>>  $tools */
    public function useTools(array $tools): void
    {
        $this->tools = array_values($tools);
    }

    /**
     * Tools appended to the base AgentServer's own list — draw-chart — when no server class declares one and no
     * list was given: AgentsPlugin adds show-table in a panel with agent resources. A closure is read on each call.
     *
     * @param  list<class-string<Tool>>|Closure  $tools
     */
    public function addTools(array|Closure $tools): void
    {
        $this->addedTools = $tools;
    }

    /**
     * Every tool of the product, in the order the model sees them: the list
     * given to the plugin, the default $tools of the MCP server class, or —
     * on the base server — the package's generic tools plus what addTools() added.
     *
     * @return list<class-string<Tool>>
     */
    public function toolClasses(): array
    {
        if ($this->tools !== []) {
            return $this->withOptInTools($this->tools);
        }

        $property = new ReflectionProperty($this->serverClass(), 'tools');
        $default = array_values((array) $property->getDefaultValue());

        if ($property->getDeclaringClass()->getName() !== AgentServer::class) {
            return $this->withOptInTools($default);
        }

        $added = $this->addedTools instanceof Closure ? ($this->addedTools)() : $this->addedTools;

        return $this->withOptInTools([...$default, ...array_values((array) $added)]);
    }

    /**
     * The package's tools an app switches on rather than lists: search-knowledge-base once a searchable knowledge
     * base is registered. They join whatever list is served, the app's own included.
     *
     * @return list<class-string<Tool>>
     */
    public function optInTools(): array
    {
        return $this->knowledge()?->searchable() ? [SearchKnowledgeBase::class] : [];
    }

    /**
     * @param  list<class-string<Tool>>  $tools
     * @return list<class-string<Tool>>
     */
    protected function withOptInTools(array $tools): array
    {
        return array_values(array_unique([...$tools, ...$this->optInTools()]));
    }

    /**
     * The app's own documents for the assistant to answer "how do I…" from: a model with an embedding column
     * (pgvector) searched by the search-knowledge-base tool, provider-hosted vector stores searched by laravel/ai's
     * FileSearch, or a search of the app's own (`using`). See KnowledgeBase for the arguments; config
     * `knowledge_base` does the same without closures.
     *
     * @param  class-string<Model>|null  $model
     * @param  list<string>  $stores
     */
    public function knowledgeBase(
        ?string $model = null,
        string $column = 'embedding',
        string|Closure $title = 'title',
        string|Closure $content = 'content',
        string|Closure|null $url = null,
        float $minSimilarity = 0.5,
        int $limit = 5,
        ?Closure $query = null,
        ?Closure $using = null,
        array $stores = [],
        ?string $ability = null,
    ): void {
        $this->knowledgeBase = new KnowledgeBase($model, $column, $title, $content, $url, $minSimilarity, $limit, $query, $using, array_values($stores), $ability);
    }

    /** The knowledge base the app registered, or the one config `knowledge_base` describes; null without either. */
    public function knowledge(): ?KnowledgeBase
    {
        return $this->knowledgeBase ?? KnowledgeBase::fromConfig();
    }

    /**
     * A redaction of the app's own, run after the built-in patterns wherever config `redact` applies —
     * fn (string $text): string, returning the text with whatever must not be shown or stored replaced.
     */
    public function redactUsing(?Closure $callback): void
    {
        $this->redactor = $callback;
    }

    public function redactor(): ?Closure
    {
        return $this->redactor;
    }

    /**
     * Change what a tool returns before the model reads it, for the chat and MCP clients alike:
     * fn (array $result, AgentTool $tool, Laravel\Mcp\Request $request): array, run after the tool's run(), in the
     * order given; null forgets every one given before. A callback that throws fails the call as a tool error.
     */
    public function mapToolResultsUsing(?Closure $callback): void
    {
        $this->toolResultMaps = $callback ? [...$this->toolResultMaps, $callback] : [];
    }

    /** @return list<Closure> */
    public function toolResultMaps(): array
    {
        return $this->toolResultMaps;
    }

    /** @param  list<class-string<AgentResource>>  $resources */
    public function useResources(array $resources): void
    {
        $this->resources = array_values($resources);
    }

    /**
     * The resources the assistant may show as live tables and use as page
     * context: the list given to useResources(), or — in a panel — every
     * resource of the panel that implements AgentResource.
     *
     * @return list<class-string<AgentResource>>
     */
    public function resourceClasses(): array
    {
        return $this->context()->resourceClasses();
    }

    /**
     * The list given to useResources(), as it was given (the context decides what to add to it).
     *
     * @return list<class-string<AgentResource>>
     */
    public function registeredResources(): array
    {
        return $this->resources;
    }

    /** @param  list<class-string|object|Closure>  $middleware */
    public function useMiddleware(array $middleware): void
    {
        $this->middleware = array_values($middleware);
    }

    /**
     * The app's own agent middleware, in the order it runs after the package's guard rails: the classes in
     * config('packstub-agents.middleware'), then what the plugin was given. Class names are resolved from the
     * container on every call, so a middleware may take dependencies in its constructor.
     *
     * @return list<object|Closure>
     */
    public function middleware(): array
    {
        return collect([...(array) config('packstub-agents.middleware', []), ...$this->middleware])
            ->map(fn (mixed $middleware) => is_string($middleware) ? app($middleware) : $middleware)
            ->values()
            ->all();
    }

    public function authorizeUsing(Closure $callback): void
    {
        $this->authorize = $callback;
    }

    /**
     * May the current person do this? null = any member. Without a callback
     * the ability goes through the Gate when one is defined and is otherwise
     * allowed, so an app without abilities still works out of the box.
     */
    public function allows(?string $ability): bool
    {
        if ($ability === null || $ability === '') {
            return true;
        }

        if ($this->authorize) {
            return (bool) ($this->authorize)($ability);
        }

        return Gate::has($ability) ? Gate::allows($ability) : true;
    }

    public function roleLabelUsing(Closure $callback): void
    {
        $this->roleLabel = $callback;
    }

    /** The current person's role as a label ("Warehouse"), for prompts and refusals; null when the app has no roles. */
    public function roleLabel(): ?string
    {
        $label = $this->roleLabel ? ($this->roleLabel)() : null;

        return $label !== null && $label !== '' ? (string) $label : null;
    }

    public function credentialsUsing(Closure $callback): void
    {
        $this->credentials = $callback;
    }

    /** The workspace's own provider, key and preferred model, when it brought its own. */
    public function credentials(): ?WorkspaceCredentials
    {
        $credentials = $this->credentials ? ($this->credentials)() : null;

        return $credentials instanceof WorkspaceCredentials ? $credentials : null;
    }

    /**
     * How the email channel finds the person behind a sender address — fn (string $email): ?Model. Without it the
     * guard's user provider is asked for the address (an `email` credential).
     */
    public function participantByEmailUsing(Closure $callback): void
    {
        $this->participantByEmail = $callback;
    }

    /** The person a sender address belongs to, or null (a mail from nobody is dropped). */
    public function participantByEmail(string $email): (Model&Authenticatable)|null
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        if ($this->participantByEmail) {
            $user = ($this->participantByEmail)($email);
        } else {
            $user = Auth::guard($this->context()->guard())->getProvider()?->retrieveByCredentials(['email' => $email]);
        }

        return $user instanceof Model && $user instanceof Authenticatable ? $user : null;
    }

    public function limitsAuthorizeUsing(Closure $callback): void
    {
        $this->limitsAuthorize = $callback;
    }

    /** May the current user edit the operator's AI limits? */
    public function canManageLimits(): bool
    {
        return $this->limitsAuthorize ? (bool) ($this->limitsAuthorize)() : auth()->check();
    }

    public function agentAccess(?string $ability, Closure|string|null $group): void
    {
        $this->agentAccessAbility = $ability;
        $this->agentAccessGroup = $group;
    }

    public function agentAccessAbility(): ?string
    {
        return $this->agentAccessAbility;
    }

    /** Route name patterns where the topbar "Ask …" button stays hidden (the chat itself, a home page with its own composer). */
    public function hideAskButtonOn(array $patterns): void
    {
        $this->askButtonHiddenOn = array_values(array_unique([...$this->askButtonHiddenOn, ...$patterns]));
    }

    /** @return list<string> */
    public function askButtonHiddenOn(): array
    {
        return $this->askButtonHiddenOn;
    }

    public function agentAccessGroup(): ?string
    {
        $group = $this->agentAccessGroup instanceof Closure ? ($this->agentAccessGroup)() : $this->agentAccessGroup;

        return $group !== null ? (string) $group : null;
    }
}
