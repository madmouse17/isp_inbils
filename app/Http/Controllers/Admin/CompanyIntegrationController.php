<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyIntegrationRequest;
use App\Jobs\TestCompanyIntegration;
use App\Models\Core\CompanyIntegration;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Push\FcmSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\NetworkAsset\Jobs\SyncCompanyNetworkJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\RadiusException;

/**
 * Pengaturan → Integrasi: the company's own Midtrans, WhatsApp, e-mail and RADIUS accounts. Secrets are write-only (the
 * page gets CompanyIntegration::masked()), every save needs an authenticator code (UpdateCompanyIntegrationRequest) and
 * is audited without values, and "Uji koneksi" is queued, so no request waits on a provider.
 */
class CompanyIntegrationController extends Controller
{
    public function edit(Request $request): Response
    {
        $company = CompanyService::current() ?? abort(403, 'Konteks perusahaan diperlukan.');
        $rows = CompanyIntegration::query()->get()->keyBy('provider');

        return Inertia::render('Admin/Company/Integrations', [
            'integrations' => collect(CompanyIntegration::PROVIDERS)
                ->mapWithKeys(fn (string $provider): array => [$provider => $this->card((int) $company->id, $provider, $rows->get($provider))])
                ->all(),
            'webhooks' => [
                'midtrans' => url('/webhooks/midtrans/'.$company->public_id),
                'whatsapp' => url('/webhooks/whatsapp'),
            ],
            // Platform-wide: the status of the one Firebase project, never a value.
            'firebase' => [
                'configured' => FcmSender::isConfigured(),
                'last_sent_at' => Cache::get('push:last_sent_at'),
            ],
            'mailFallback' => ['from_address' => config('mail.from.address')],
            'twoFactorEnabled' => $request->user()->hasTwoFactorEnabled(),
        ]);
    }

    public function update(UpdateCompanyIntegrationRequest $request, string $provider): RedirectResponse
    {
        DB::transaction(function () use ($request, $provider): void {
            $row = CompanyIntegration::query()->where('provider', $provider)->lockForUpdate()->first()
                ?? new CompanyIntegration(['provider' => $provider]);
            $data = $request->validated();

            $fields = [];
            $credentials = $row->credentials ?? [];

            // A blank secret keeps the stored one: secrets can be replaced, never read back.
            foreach (CompanyIntegration::SECRETS[$provider] as $key) {
                if (filled($data[$key] ?? null) && $data[$key] !== $row->secret($key)) {
                    $credentials[$key] = $data[$key];
                    $fields[] = $key;
                }
            }

            // Settings are merged, so the keys only the import writes (base_url, transport, ...) survive a save.
            $settings = $row->settings ?? [];
            $plain = Arr::except($data, ['code', 'is_enabled', ...CompanyIntegration::SECRETS[$provider]]);

            foreach ($plain as $key => $value) {
                $value = $this->normalised($key, $value);

                if ($key === 'phone_number_id') {
                    if ($value !== $row->external_id) {
                        $row->external_id = $value;
                        $fields[] = $key;
                    }
                } elseif (($settings[$key] ?? null) !== $value) {
                    $settings[$key] = $value;
                    $fields[] = $key;
                }
            }

            $row->is_enabled = (bool) $data['is_enabled'];
            $row->settings = $settings;

            if ($fields !== []) {
                $row->credentials = $credentials === [] ? null : $credentials;
                // What was verified is no longer what is stored.
                $row->verified_at = null;
                $row->last_error = null;
            }

            $row->save();

            AuditService::log('integration', 'saved', ['provider' => $provider, 'fields' => $fields, 'is_enabled' => $row->is_enabled], $row);
        });

        if ($provider === 'radius' && $request->boolean('is_enabled')) {
            $this->radiusIsLive();
        }

        return back()->with('success', 'Integrasi '.CompanyIntegration::LABELS[$provider].' disimpan.');
    }

    public function test(Request $request, string $provider): RedirectResponse
    {
        $label = CompanyIntegration::LABELS[$provider];
        $row = CompanyIntegration::query()->where('provider', $provider)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['provider' => "Simpan pengaturan {$label} dulu."]);
        }

        // One check at a time per company and provider; the job clears the key, and 60 s frees it if the job never ran.
        if (! Cache::add(TestCompanyIntegration::checkingKey((int) $row->company_id, $provider), now()->timestamp, 60)) {
            return back()->with('warning', "Uji koneksi {$label} sedang berjalan.");
        }

        TestCompanyIntegration::dispatch($row->id, $request->user()->id);

        return back()->with('success', "Uji koneksi {$label} berjalan.");
    }

    /** @return array<string, mixed> */
    private function card(int $companyId, string $provider, ?CompanyIntegration $row): array
    {
        // A provider without a row is shown as an empty card with its defaults.
        $shown = $row ?? new CompanyIntegration(['provider' => $provider]);

        return [
            'provider' => $provider,
            'state' => CompanyIntegration::stateOf($row),
            'is_enabled' => $shown->is_enabled,
            'checking' => Cache::has(TestCompanyIntegration::checkingKey($companyId, $provider)),
            'verified_at' => $row?->verified_at?->toIso8601String(),
            'last_checked_at' => $row?->last_checked_at?->toIso8601String(),
            'last_error' => $row?->last_error,
            'settings' => $shown->publicSettings(),
            'secrets' => $shown->masked(),
        ];
    }

    /** The type a field is stored as, so a form that sends "587" and one that sends 587 save the same row. */
    private function normalised(string $key, mixed $value): mixed
    {
        return match ($key) {
            'port' => $value === null ? null : (int) $value,
            'is_production' => (bool) $value,
            'phone_number_id' => $value === null ? null : (string) $value,
            default => $value,
        };
    }

    /**
     * The company saved its RADIUS card enabled: the reason accounts and NAS rows kept for waiting ("belum dikonfigurasi")
     * is over, so it goes now instead of at the next apply, and one sweep writes what waited for RADIUS.
     */
    private function radiusIsLive(): void
    {
        $companyId = (int) CompanyService::currentId();
        $waiting = RadiusException::disabled()->getMessage();

        NetworkAccount::forCompany($companyId)->where('last_error', $waiting)->update(['last_error' => null]);
        NasDevice::forCompany($companyId)->where('last_error', $waiting)->update(['last_error' => null]);

        SyncCompanyNetworkJob::dispatch($companyId);
    }
}
