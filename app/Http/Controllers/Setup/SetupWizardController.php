<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreCompanySetupRequest;
use App\Services\Core\SettingService;
use App\Services\Core\SetupWizardService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupWizardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Setup/Wizard', [
            'user' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'defaults' => [
                'currency' => SettingService::get('default_currency', 'IDR'),
                'timezone' => SettingService::get('default_timezone', 'Asia/Jakarta'),
                'date_format' => SettingService::get('default_date_format', 'd M Y'),
                'datetime_format' => 'd M Y H:i',
                'timezones' => DateTimeZone::listIdentifiers(),
            ],
        ]);
    }

    public function store(StoreCompanySetupRequest $request, SetupWizardService $service): RedirectResponse
    {
        $service->create($request->validated());

        return redirect('/dashboard')->with('success', 'Setup complete.');
    }
}
