<?php

namespace Packstub\Agents;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Mcp\Facades\Mcp;
use Packstub\Agents\Chat\ChatAssets;
use Packstub\Agents\Chat\ChatHost;
use Packstub\Agents\Chat\ChatParts;
use Packstub\Agents\Chat\ChatRoutes;
use Packstub\Agents\Chat\Components\Chat;
use Packstub\Agents\Chat\Components\Chats;
use Packstub\Agents\Chat\Hosts\RouteHost;
use Packstub\Agents\Commands\EmbedCommand;
use Packstub\Agents\Commands\MakeAgentCommand;
use Packstub\Agents\Commands\MakeToolCommand;
use Packstub\Agents\Commands\RunCommand;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Contracts\DecisionClassifier;
use Packstub\Agents\Http\Controllers\EmailInboundController;
use Packstub\Agents\Http\Middleware\AcceptJson;
use Packstub\Agents\Http\Middleware\AuthenticateEmailWebhook;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentRedactor;
use Packstub\Agents\Support\AgentToolLog;
use Packstub\Agents\Support\Context\LaravelContext;
use Packstub\Agents\Support\Decisions\AgentDecisionClassifier;
use Packstub\Agents\Support\Decisions\JevDecisionClassifier;
use Packstub\Agents\Support\Installed;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AgentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('packstub-agents')
            ->hasConfigFile()
            ->discoversMigrations()
            // Auto-run by default; database-per-tenant apps set run_migrations=false, publish and split them.
            ->runsMigrations((bool) config('packstub-agents.run_migrations', true))
            ->hasCommand(MakeAgentCommand::class)
            ->hasCommand(MakeToolCommand::class)
            ->hasCommand(RunCommand::class)
            ->hasCommand(EmbedCommand::class)
            ->hasViews('packstub-agents-mail')
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $command->info('Installing Packstub Agents…'))
                    ->publishConfigFile()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $command->call('packstub-agents:agent');

                        if (Installed::filamentAgents()) {
                            $command->info('Next: register the plugin in your panel provider —');
                            $command->line('    ->plugin(\\'.Installed::plugin().'::make()->name(\'Ask …\')->agent(\App\Ai\Agents\Assistant::class)->tools([...]))');
                            $command->line('add a provider key to .env (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY or XAI_API_KEY, with AGENT_PROVIDER), run `php artisan filament:assets`,');
                            $command->line('and add the package views to your theme: @source \'../../../../vendor/packstub/filament-agents/resources/views\';');
                        } else {
                            $command->info('Next: register the agent and the tools in a service provider —');
                            $command->line('    Agents::useAgent(\App\Ai\Agents\Assistant::class); Agents::useTools([...]);');
                            $command->line('and add a provider key to .env (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY or XAI_API_KEY, with AGENT_PROVIDER).');

                            if (Installed::filament()) {
                                $command->line('For the chat and the operator pages in your panel: composer require packstub/filament-agents.');
                            }
                        }
                    });
            });
    }

    /** Deep-merge the package's config defaults under any user-published values (mergeConfigFrom is top-level only). */
    public function packageRegistered(): void
    {
        $defaults = require __DIR__.'/../config/packstub-agents.php';

        config()->set('packstub-agents', array_replace_recursive($defaults, config('packstub-agents', [])));

        $this->app->singleton(AgentsManager::class);

        // One redactor per request or job: it remembers what a turn replaced, to report it once.
        $this->app->scoped(AgentRedactor::class);
        // The turn and the call under way, for the tool log.
        $this->app->scoped(AgentToolLog::class);

        // Who is acting and where: a plain Laravel app's guard and workspace closure; AgentsPlugin rebinds the panel's.
        $this->app->singleton(AgentContext::class, LaravelContext::class);

        // Where the chat is rendered: a plain Laravel app's named routes and the package's own Blade components;
        // AgentsPlugin rebinds the panel's. The parts of one render (what the page already shows) live per request.
        $this->app->singleton(ChatHost::class, RouteHost::class);
        $this->app->scoped(ChatParts::class);

        // What reads a typed reply the word lists cannot: the driver config names, or a class of the app's; an app may
        // also bind its own DecisionClassifier.
        $this->app->bind(DecisionClassifier::class, fn ($app) => $app->make(match ($driver = (string) (config('packstub-agents.decision_classifier.driver') ?: 'agent')) {
            'agent' => AgentDecisionClassifier::class,
            'jev' => JevDecisionClassifier::class,
            default => $driver,
        }));
    }

    public function packageBooted(): void
    {
        // The chat records a question before the provider answers it (see AgentConversationStore).
        $this->app->singleton(ConversationStore::class, fn (): AgentConversationStore => new AgentConversationStore(config('ai.conversations.connection')));
        $this->app->alias(ConversationStore::class, AgentConversationStore::class);

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        $this->registerChatViews();

        // The routes wait for the whole app: what the app registers through the facade in its own boot(), and — with
        // packstub/filament-agents — the panels, whose plugin binds the context and mirrors the server class into config
        // before any route or job reads either (its provider resolves them in an earlier booted callback).
        $this->app->booted(function (): void {
            $this->registerMcpRoute();
            $this->registerChatRoutes();
            $this->registerEmailRoute();
        });
    }

    /**
     * POST {mcp.path} with "Authorization: Bearer <agent token>". Registered
     * once the app (and a panel's plugin) named the server.
     */
    protected function registerMcpRoute(): void
    {
        if (! (bool) config('packstub-agents.mcp.enabled', true)) {
            return;
        }

        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        // Whatever the app configures, a client that fails authentication gets a JSON 401, never a login redirect.
        Mcp::web('/'.trim((string) config('packstub-agents.mcp.path', 'mcp'), '/'), app(AgentsManager::class)->serverClass())
            ->where('tenant', '[A-Za-z0-9-]+')
            ->middleware([AcceptJson::class, ...config('packstub-agents.mcp.middleware', [])]);
    }

    /**
     * The chat's views (packstub-agents-chat::, publishable as packstub-agents-chat-views), its two components
     * (<x-agents-chat />, <x-agents-chats />) and the directives the views are written with.
     */
    protected function registerChatViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/chat', 'packstub-agents-chat');
        $this->publishes([__DIR__.'/../resources/views/chat' => resource_path('views/vendor/packstub-agents-chat')], 'packstub-agents-chat-views');

        Blade::component('agents-chat', Chat::class);
        Blade::component('agents-chats', Chats::class);

        // A part of the chat with a key and a revision: see ChatParts.
        Blade::directive('agentsPart', fn (string $expression): string => "<?php if (app(\\Packstub\\Agents\\Chat\\ChatParts::class)->begin({$expression})): ?>");
        Blade::directive('endAgentsPart', fn (): string => '<?php endif; echo app(\\Packstub\\Agents\\Chat\\ChatParts::class)->end(); ?>');
        // The stylesheet and the script, for a page that renders the chat with :assets="false".
        Blade::directive('agentsChatAssets', fn (): string => "<?php echo view('packstub-agents-chat::assets')->render(); ?>");
    }

    /**
     * The chat's endpoints outside a panel, under chat.path and chat.middleware: the turn's poll and event stream,
     * the actions, the refresh, the record search and the export (ChatRoutes), the built files, and with chat.ui.page
     * the package's own chat pages. A panel that registered the chat has its own endpoints (packstub-agents.turn on
     * the panel's authenticated tenant routes) and these are left out, unless the app also shows the chat on a page
     * of its own (chat.ui.page or chat.ui.route).
     */
    protected function registerChatRoutes(): void
    {
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        $ui = (array) config('packstub-agents.chat.ui', []);
        $wanted = (bool) ($ui['page'] ?? false) || filled($ui['route'] ?? null);

        if (! $wanted) {
            foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
                if (str_ends_with($name, '.packstub-agents.turn')) {
                    return;
                }
            }
        }

        $path = trim((string) config('packstub-agents.chat.path', 'agents'), '/');
        $middleware = (array) config('packstub-agents.chat.middleware', ['web', 'auth']);

        Route::get($path.'/assets/{file}', fn () => ChatAssets::serve((string) request()->route('file')))->name('packstub-agents.asset');

        Route::middleware($middleware)->group(function () use ($path, $ui): void {
            ChatRoutes::endpoints($path);

            if ((bool) ($ui['page'] ?? false)) {
                ChatRoutes::pages($path);
            }
        });
    }

    /** POST {chat.path}/email — the inbound mail webhook, behind the shared secret (404 while the channel is off). */
    protected function registerEmailRoute(): void
    {
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        Route::post(trim((string) config('packstub-agents.chat.path', 'agents'), '/').'/email', EmailInboundController::class)
            ->middleware([...(array) config('packstub-agents.email.middleware', ['api']), AuthenticateEmailWebhook::class])
            ->name('packstub-agents.email');
    }
}
