<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Services\Core\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Where logins without web access land: it names the Android app of a technician or a customer, or says no web access was given. */
class AppOnlyController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! RedirectAppOnlyRoles::isAppOnly($user)) {
            return redirect()->route('admin.dashboard');
        }

        // A login that qualifies for both apps is shown the technician app; the customer app is the other half of the pair.
        $app = match (true) {
            $user->can('app.technician') => 'technician',
            $user->can('portal.access') => 'customer',
            default => null,
        };

        return Inertia::render('AppOnly', [
            'role' => $app,
            'appName' => match ($app) {
                'technician' => 'Inbils Teknisi',
                'customer' => 'Inbils Pelanggan',
                default => null,
            },
            'playStoreUrl' => $app === null ? null : SettingService::playStoreUrl($app),
        ]);
    }
}
