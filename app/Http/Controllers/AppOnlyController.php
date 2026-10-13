<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Services\Core\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Where technician and customer logins land on the web: it names their app and nothing else. */
class AppOnlyController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! RedirectAppOnlyRoles::isAppOnly($user)) {
            return redirect()->route('admin.dashboard');
        }

        // A login holding both roles is shown the technician app; the customer app is the other half of the pair.
        $role = $user->hasRole('technician') ? 'technician' : 'customer';

        return Inertia::render('AppOnly', [
            'role' => $role,
            'appName' => $role === 'technician' ? 'Inbils Teknisi' : 'Inbils Pelanggan',
            'playStoreUrl' => SettingService::playStoreUrl($role),
        ]);
    }
}
