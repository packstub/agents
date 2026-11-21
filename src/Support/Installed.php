<?php

namespace Packstub\Agents\Support;

use Filament\FilamentServiceProvider;

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
        return self::plugin() !== null;
    }

    /**
     * The plugin's class: Packstub\FilamentAgents\AgentsPlugin since filament-agents 1.20, which gave the plugin a
     * namespace of its own; Packstub\Agents\AgentsPlugin before. Named as strings: the engine never imports it.
     *
     * @return class-string|null
     */
    public static function plugin(): ?string
    {
        foreach (['Packstub\\FilamentAgents\\AgentsPlugin', 'Packstub\\Agents\\AgentsPlugin'] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }
}
