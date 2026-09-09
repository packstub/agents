<?php

namespace Packstub\Agents\Support;

use Filament\FilamentServiceProvider;
use Packstub\Agents\AgentsPlugin;

/** What the app runs on, for the parts of the package that read differently in a panel (the scaffold hints, the install notes). */
class Installed
{
    /** Filament is installed and its service provider is loaded — not merely on the autoloader. */
    public static function filament(): bool
    {
        return class_exists(FilamentServiceProvider::class) && app()->providerIsLoaded(FilamentServiceProvider::class);
    }

    /** packstub/filament-agents — the chat and the operator pages — is installed, so the app registers through its plugin. */
    public static function filamentAgents(): bool
    {
        return class_exists(AgentsPlugin::class);
    }
}
