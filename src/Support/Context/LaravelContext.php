<?php

namespace Packstub\Agents\Support\Context;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Exceptions\WorkspaceNotFound;
use Packstub\Agents\Facades\Agents;

/**
 * The context of a plain Laravel app, without a panel: the guard in use
 * (the default one, or whatever auth middleware picked), the workspace from
 * the closure registered with Agents::tenantUsing() — null means one
 * workspace — and its model from Agents::tenantModel(), so a worker and an
 * MCP request can find it again by key or by slug; entering one runs the
 * Agents::enteringTenant() hook, and what it returned runs on leaving. Membership goes through
 * the user's own canAccessTenant() when it has one; without it every
 * signed-in person may enter every workspace. A workspace with no person at
 * all is refused too, unless the caller passes `system => true` (a scheduled
 * job acting for the app, not for anyone).
 */
class LaravelContext implements AgentContext
{
    protected ?Model $entered = null;

    protected bool $isEntered = false;

    public function user(): ?Authenticatable
    {
        return Auth::guard($this->guard())->user();
    }

    public function guard(): string
    {
        return (string) Auth::getDefaultDriver();
    }

    public function tenant(): ?Model
    {
        if ($this->isEntered) {
            return $this->entered;
        }

        $resolver = Agents::tenantResolver();
        $tenant = $resolver ? $resolver() : null;

        return $tenant instanceof Model ? $tenant : null;
    }

    public function locale(): string
    {
        return app()->getLocale();
    }

    public function capture(): array
    {
        return [
            'panel' => null,
            'tenant' => $this->tenant()?->getKey(),
            'user' => $this->user()?->getAuthIdentifier(),
            'locale' => $this->locale(),
            'guard' => $this->guard(),
        ];
    }

    public function enter(array $context): Closure
    {
        $previousLocale = app()->getLocale();
        $previousGuard = $this->guard();
        $previousEntered = [$this->isEntered, $this->entered];

        $guardName = filled($context['guard'] ?? null) ? (string) $context['guard'] : $previousGuard;
        $guard = Auth::guard($guardName);
        $previousUser = $guard->user();
        $given = $context['user'] ?? null;
        $user = $given instanceof Authenticatable ? $given : ($given !== null ? $guard->getProvider()?->retrieveById($given) : null);
        $userChanged = $user && $previousUser?->getAuthIdentifier() !== $user->getAuthIdentifier();

        if ($user) {
            $guard->setUser($user);
        }

        Auth::shouldUse($guardName);

        $key = $context['tenant'] ?? null;
        $tenant = $key !== null ? $this->findTenant($key) : null;
        $leaveTenant = null;

        // Whoever acts inside the workspace: the person given, else the one already signed in on the guard.
        // Nobody at all is not a membership we checked: refused, unless the app says the system itself acts.
        $actor = $user ?? $previousUser;

        $refused = match (true) {
            $key !== null && ! $tenant && $this->tenantModel() !== null => WorkspaceNotFound::make(),
            $tenant && $actor && ! $this->canAccessTenant($actor, $tenant) => WorkspaceAccessDenied::make(),
            $tenant && ! $actor && ! ($context['system'] ?? false) => WorkspaceAccessDenied::make(),
            default => null,
        };

        if ($refused) {
            // Fail closed before anything is entered: undo what was set so far and refuse.
            if ($userChanged) {
                $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            }

            Auth::shouldUse($previousGuard);

            throw $refused;
        }

        if ($tenant) {
            $this->isEntered = true;
            $this->entered = $tenant;

            if ($hook = Agents::tenantEnterHook()) {
                $left = $hook($tenant);
                $leaveTenant = $left instanceof Closure ? $left : null;
            }
        }

        if (filled($context['locale'] ?? null)) {
            app()->setLocale((string) $context['locale']);
        }

        return function () use ($previousLocale, $previousGuard, $previousEntered, $previousUser, $userChanged, $guard, $leaveTenant): void {
            [$this->isEntered, $this->entered] = $previousEntered;

            if ($leaveTenant) {
                $leaveTenant();
            }

            if ($userChanged) {
                $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            }

            Auth::shouldUse($previousGuard);
            app()->setLocale($previousLocale);
        };
    }

    public function tenantModel(): ?string
    {
        return Agents::tenantModelClass();
    }

    public function findTenant(int|string $key): ?Model
    {
        $model = $this->tenantModel();

        return $model && self::fitsKey(new $model, $key) ? $model::query()->find($key) : null;
    }

    public function findTenantBySlug(string $slug): ?Model
    {
        $model = $this->tenantModel();

        if (! $model) {
            return null;
        }

        if (($attribute = Agents::tenantSlugAttribute()) === null && ! self::fitsKey(new $model, $slug)) {
            return null;
        }

        return $model::query()->where($attribute ?? (new $model)->getKeyName(), $slug)->first();
    }

    /** Whether the key can be the model's: Postgres refuses "acme" for an integer key where the others match nothing. */
    public static function fitsKey(Model $model, int|string $key): bool
    {
        return $model->getKeyType() !== 'int' || is_int($key) || ctype_digit($key);
    }

    public function canAccessTenant(Authenticatable $user, Model $tenant): bool
    {
        return ! method_exists($user, 'canAccessTenant') || (bool) $user->canAccessTenant($tenant);
    }

    public function resourceClasses(): array
    {
        return Agents::registeredResources();
    }

    public function inPanel(): bool
    {
        return false;
    }
}
