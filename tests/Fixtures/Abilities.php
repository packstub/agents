<?php

namespace Packstub\Agents\Tests\Fixtures;

/** The app's authorization, as tests see it: a list of abilities the current person has, plus a role label. */
class Abilities
{
    /** @var list<string> */
    public static array $allowed = ['*'];

    public static ?string $role = 'Owner';

    /**
     * Refused whatever $allowed says ('widgets.view.3': one record the person may not open).
     *
     * @var list<string>
     */
    public static array $denied = [];

    public static function allows(string $ability): bool
    {
        if (in_array($ability, self::$denied, true)) {
            return false;
        }

        return in_array('*', self::$allowed, true) || in_array($ability, self::$allowed, true);
    }

    public static function reset(): void
    {
        self::$allowed = ['*'];
        self::$denied = [];
        self::$role = 'Owner';
    }
}
