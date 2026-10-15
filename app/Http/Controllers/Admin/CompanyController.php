<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyProfileRequest;
use App\Http\Requests\Admin\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanyResource;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return back()->with('success', 'Profil perusahaan diperbarui.');
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
                'auto_suspend_enabled' => false,
                'auto_suspend_overdue_days' => 7,
                'auto_reactivate_on_payment' => true,
                // Null = follow the system default; the resolved value is the shared `company.map` prop.
                'map_city_code' => null,
                'map_lat' => null,
                'map_lng' => null,
                'map_zoom' => null,
                ...WhatsAppSettings::defaults(),
            ], $company->settings ?? []),
            'cities' => $this->cities(),
            // Whether credentials exist, never their values; the usage counts this company's current month.
            'whatsapp' => [
                'configured' => app(WhatsAppCloudClient::class)->isConfigured(),
                'usage' => WhatsAppSettings::for($company->id)->usage(),
                'events' => collect(config('whatsapp.events'))
                    ->map(fn (array $event, string $key): array => ['key' => $key, 'label' => $event['label'], 'audience' => $event['audience']])
                    ->values()
                    ->all(),
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

        return back()->with('success', 'Pengaturan perusahaan diperbarui.');
    }

    /** Every kota/kabupaten for the default-map picker, with the point the picker jumps to. @return list<array{code: string, name: string, province_name: string, lat: float, lng: float}> */
    private function cities(): array
    {
        return DB::table('indonesia_cities as city')
            ->join('indonesia_provinces as province', 'province.code', '=', 'city.province_code')
            ->orderBy('province.name')
            ->orderBy('city.name')
            ->get(['city.code', 'city.name', 'province.name as province_name', 'city.meta'])
            ->map(function (object $city): array {
                $meta = json_decode((string) $city->meta, true) ?? [];

                return [
                    'code' => $city->code,
                    'name' => $city->name,
                    'province_name' => $city->province_name,
                    'lat' => (float) ($meta['lat'] ?? 0),
                    'lng' => (float) ($meta['long'] ?? 0),
                ];
            })
            ->all();
    }
}
