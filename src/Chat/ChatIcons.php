<?php

namespace Packstub\Agents\Chat;

/** The Heroicons the chat's own components draw, shipped as SVG files in resources/svg (MIT, © Tailwind Labs). */
class ChatIcons
{
    /** @var array<string, string> */
    protected static array $cache = [];

    /** The icon's SVG ("heroicon-m-pencil"), or an empty string for one the package does not ship. */
    public static function svg(?string $icon): string
    {
        if ($icon === null || ! preg_match('/^[a-z0-9-]+$/', $icon)) {
            return '';
        }

        return self::$cache[$icon] ??= is_file($path = __DIR__.'/../../resources/svg/'.$icon.'.svg') ? trim((string) file_get_contents($path)) : '';
    }
}
