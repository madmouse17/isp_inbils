<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyProfileRequest;
use App\Http\Requests\Admin\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanyResource;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\CompanyService;
use App\Services\Core\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function editProfile(Request $request): Response
    {
        $company = CompanyService::current();
        Gate::authorize('viewProfile', $company);

        return Inertia::render('Admin/Company/Profile', [
            'company' => new CompanyResource($company),
            'can' => [
                'update' => $request->user()?->can('company.manage') ?? false,
            ],
        ]);
    }

    public function updateProfile(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $company = CompanyService::current();
        Gate::authorize('updateProfile', $company);

        $data = $request->validated();
        unset($data['logo']);

        CompanyService::updateProfile($data);

        if ($request->hasFile('logo')) {
            CompanyLogoService::store($company, $request->file('logo'));
        }

        return back()->with('success', 'Company profile updated.');
    }

    public function editSettings(Request $request): Response
    {
        $company = CompanyService::current();
        Gate::authorize('viewSettings', $company);

        return Inertia::render('Admin/Company/Settings', [
            'settings' => array_merge([
                'tax_ppn_rate' => 11,
                'invoice_due_days' => 14,
                'bank_account_info' => '',
            ], $company->settings ?? []),
            'defaults' => [
                'app_name' => SettingService::get('default_app_name'),
                'currency' => SettingService::get('default_currency'),
                'timezone' => SettingService::get('default_timezone'),
            ],
            'can' => [
                'update' => $request->user()?->can('company.manage') ?? false,
            ],
        ]);
    }

    public function updateSettings(UpdateCompanySettingsRequest $request): RedirectResponse
    {
        $company = CompanyService::current();
        Gate::authorize('updateSettings', $company);

        CompanyService::updateSettings($request->validated('settings'));

        return back()->with('success', 'Company settings updated.');
    }
}
