<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebSearch;
use Throwable;

/**
 * The provider-run tools of the chat — web search, file search over a vector
 * store — exist on some providers only, and laravel/ai refuses a step that
 * hands one to a provider without it. A turn may run on several (the picker
 * entry, then the failover list), so each step keeps only the provider tools
 * its own provider runs: the same assistant answers without web search on a
 * local model and with it on Claude.
 */
class SupportedProviderTools
{
    public function handle(PendingStep $step, Closure $next)
    {
        $providerTools = array_filter($step->tools, fn ($tool) => $tool instanceof ProviderTool);

        if ($providerTools === []) {
            return $next($step);
        }

        try {
            $provider = Ai::textProvider($step->provider);
        } catch (Throwable) {
            return $next($step);
        }

        $kept = array_filter($step->tools, fn ($tool) => match (true) {
            $tool instanceof WebSearch => $provider instanceof SupportsWebSearch,
            $tool instanceof FileSearch => $provider instanceof SupportsFileSearch,
            default => true,
        });

        return $next(count($kept) === count($step->tools) ? $step : $step->withTools($kept));
    }
}
