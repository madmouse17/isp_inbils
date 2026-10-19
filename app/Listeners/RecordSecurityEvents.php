<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use App\Support\SecurityTrail;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\PermissionRegistrar;

/**
 * The security trail's rows that Laravel and the permission package announce as events. The failed login is not an
 * event, so SecurityTrail writes it; the secret and the 2FA challenge are written where they happen. Every row is
 * ids and names only: never a secret, a token or the typed address.
 */
class RecordSecurityEvents
{
    /** A web sign-in. An app sign-in fires this too, under the `sanctum` guard, and has its own row (api.token_issued). */
    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        // The guard sets its user only after this event, so the one who signs in is named as the causer.
        AuditService::log('security', 'login', ['user_id' => $event->user->id, 'remember' => (bool) $event->remember], $event->user, causer: $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        AuditService::log('security', 'logout', ['user_id' => $event->user->id], $event->user, causer: $event->user);
    }

    /**
     * A refused attempt, one row per route, address and typed address in a window: a blocked client keeps sending, and
     * a row for each would let it fill the log. A route without a name is told by its URI template, which holds no ids.
     */
    public function handleLockout(Lockout $event): void
    {
        $request = $event->request;
        $channel = $request->routeIs('platform.*') ? 'platform' : ($request->is('api/*') ? 'api' : 'web');
        $route = $request->route()?->getName() ?? $request->route()?->uri();
        // Only the login forms carry an address; the other steps are told apart by route and client address.
        $hash = is_string($email = $request->input('email')) && $email !== '' ? SecurityTrail::emailHash($email) : null;

        if (! Cache::add('security:lockout:'.sha1($route.'|'.$request->ip().'|'.$hash), true, SecurityTrail::WINDOW_SECONDS)) {
            return;
        }

        // The platform plane is never read from the tenant guard: a tenant session in the same browser is no operator.
        $account = $channel === 'platform' ? $request->user('platform') : $request->user();

        AuditService::log(
            $channel === 'platform' ? PlatformAuditService::PLATFORM_LOG : 'security',
            'lockout',
            ['route' => $route, 'channel' => $channel, ...($hash === null ? [] : ['email_hash' => $hash])],
            $account instanceof Model ? $account : null,
        );
    }

    /** Fired by the broker only for an account that exists, so an unknown address leaves no row to tell it apart. */
    public function handleResetLinkSent(PasswordResetLinkSent $event): void
    {
        if ($event->user instanceof User) {
            AuditService::log('security', 'password.reset_requested', ['user_id' => $event->user->id], $event->user);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            AuditService::log('security', 'password.reset', ['user_id' => $event->user->id], $event->user);
        }
    }

    public function handleRoleAttached(RoleAttached $event): void
    {
        $this->recordRoles($event->model, $event->rolesOrIds, 'role.attached');
    }

    public function handleRoleDetached(RoleDetached $event): void
    {
        $this->recordRoles($event->model, $event->rolesOrIds, 'role.detached');
    }

    /**
     * Only a user's roles are a security matter (a permission can hold roles too), and only a saved user has an id to
     * name: assigning before the first save attaches later, and no caller does it. An empty list writes nothing, so a
     * caller that passes only what changed records only what changed.
     */
    private function recordRoles(Model $model, mixed $rolesOrIds, string $action): void
    {
        if (! $model instanceof User || ! $model->exists) {
            return;
        }

        $ids = collect(Arr::wrap($rolesOrIds))
            ->flatten()
            ->map(fn (mixed $role): mixed => $role instanceof Model ? $role->getKey() : $role)
            ->all();

        if ($ids === []) {
            return;
        }

        /** @var class-string<Model> $roleClass */
        $roleClass = app(PermissionRegistrar::class)->getRoleClass();
        $names = $roleClass::query()->whereKey($ids)->orderBy('name')->pluck('name')->all();

        if ($names !== []) {
            AuditService::log('security', $action, ['user_id' => $model->id, 'roles' => $names], $model);
        }
    }
}
