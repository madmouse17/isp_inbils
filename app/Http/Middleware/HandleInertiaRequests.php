<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'company_id' => $user->company_id,
                    'is_active' => $user->is_active ?? true,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'company' => $user?->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'code' => $user->company->code,
                'logo' => $user->company->getFirstMediaUrl('logo') ?: null,
                'currency' => $user->company->currency,
                'timezone' => $user->company->timezone,
                'settings' => $user->company->settings,
            ] : null,
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
