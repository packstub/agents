<?php

namespace Packstub\Agents;

use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Mcp\Facades\Mcp;
use Packstub\Agents\Commands\MakeAgentCommand;
use Packstub\Agents\Commands\MakeToolCommand;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Http\Controllers\TurnController;
use Packstub\Agents\Http\Middleware\AcceptJson;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\Context\LaravelContext;
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
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $command->info('Installing Packstub Agents…'))
                    ->publishConfigFile()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $command->call('packstub-agents:agent');

                        if (Installed::filamentAgents()) {
                            $command->info('Next: register the plugin in your panel provider —');
                            $command->line('    ->plugin(\Packstub\Agents\AgentsPlugin::make()->name(\'Ask …\')->agent(\App\Ai\Agents\Assistant::class)->tools([...]))');
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

        // Who is acting and where: a plain Laravel app's guard and workspace closure; AgentsPlugin rebinds the panel's.
        $this->app->singleton(AgentContext::class, LaravelContext::class);
    }

    public function packageBooted(): void
    {
        // The chat records a question before the provider answers it (see AgentConversationStore).
        $this->app->singleton(ConversationStore::class, fn (): AgentConversationStore => new AgentConversationStore(config('ai.conversations.connection')));
        $this->app->alias(ConversationStore::class, AgentConversationStore::class);

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        // The routes wait for the whole app: what the app registers through the facade in its own boot(), and — with
        // packstub/filament-agents — the panels, whose plugin binds the context and mirrors the server class into config
        // before any route or job reads either (its provider resolves them in an earlier booted callback).
        $this->app->booted(function (): void {
            $this->registerMcpRoute();
            $this->registerTurnRoute();
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
     * GET {chat.path}/chat/{conversation}/turn — what a chat polls while an
     * answer is produced — outside a panel, under chat.middleware. A panel
     * that registered the chat has its own (packstub-agents.turn on the
     * panel's authenticated tenant routes) and this one is left out.
     */
    protected function registerTurnRoute(): void
    {
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
            if (str_ends_with($name, '.packstub-agents.turn')) {
                return;
            }
        }

        Route::get(trim((string) config('packstub-agents.chat.path', 'agents'), '/').'/chat/{conversation}/turn', TurnController::class)
            ->middleware((array) config('packstub-agents.chat.middleware', ['web', 'auth']))
            ->name('packstub-agents.turn');
    }
}
