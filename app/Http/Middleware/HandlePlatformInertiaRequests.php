<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use App\Support\PlatformSession;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The Inertia shell of the operator panel (/platform). It shares only what the panel renders: the signed-in
 * platform admin, the flash messages and the app name. Tenant auth, company, work queues, notifications and
 * permissions are never shared here, so a platform page cannot show or depend on tenant state.
 */
class HandlePlatformInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var PlatformAdmin|null $admin */
        $admin = $request->user('platform');

        return [
            ...parent::share($request),
            'platform' => [
                'admin' => $admin === null ? null : [
                    'id' => $admin->public_id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'two_factor_enabled' => $admin->hasTwoFactorEnabled(),
                ],
            ],
            // When the last password confirmation stops covering sensitive actions (ISO 8601), null when it does not. Only a
            // hint for the dialogs: every confirmed action is still checked by RequirePlatformPasswordConfirmation.
            'passwordConfirmedUntil' => fn () => PlatformSession::confirmedUntil($request->session())?->toIso8601String(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'app' => [
                'name' => config('app.name'),
                'locale' => 'id',
            ],
        ];
    }
}
