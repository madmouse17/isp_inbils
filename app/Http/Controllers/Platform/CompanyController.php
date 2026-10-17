<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ProvisionTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\StoreTenantRequest;
use App\Http\Requests\Platform\Tenants\TenantShowRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Queries\Platform\TenantDetailQuery;
use App\Services\Core\SettingService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** A tenant in the platform panel: provisioned here, looked at here. `{company}` is always a top-level company. */
class CompanyController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Platform/Companies/Create', [
            'defaults' => [
                'timezone' => SettingService::get('default_timezone', 'Asia/Jakarta'),
                'currency' => SettingService::get('default_currency', 'IDR'),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => StoreTenantRequest::CURRENCIES,
        ]);
    }

    public function store(StoreTenantRequest $request, ProvisionTenantAction $provision): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $company = $provision->execute($admin, $request->validated());

        return redirect()->route('platform.companies.show', $company)->with('success', 'Perusahaan dibuat. Undangan dikirim ke admin pertama.');
    }

    public function show(TenantShowRequest $request, Company $company, TenantDetailQuery $detail): Response
    {
        return Inertia::render('Platform/Companies/Show', $detail->props($company, $request->filters()));
    }
}
