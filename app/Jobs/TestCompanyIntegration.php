<?php

namespace App\Jobs;

use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Notifications\Channels\CompanyMailChannel;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Billing\Services\MidtransGateway;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Throwable;

/**
 * "Uji koneksi" of Pengaturan → Integrasi: one provider call with a five-second budget, run here so that no web request
 * waits on a provider (the check itself is the `check()` of the provider's own class). The outcome lands on the row
 * (`verified_at`, `last_error`, `last_checked_at`) and in the audit log as a message only: a message never holds a
 * secret, a response body or a phone number.
 */
final class TestCompanyIntegration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public readonly int $integrationId,
        public readonly int $userId,
    ) {}

    /** The single-flight key the web action sets and this job clears; the page reads it as `checking`. */
    public static function checkingKey(int $companyId, string $provider): string
    {
        return "integrations:checking:{$companyId}:{$provider}";
    }

    public function handle(): void
    {
        $row = CompanyIntegration::withoutCompany()->find($this->integrationId);

        if ($row === null) {
            return;
        }

        try {
            $user = User::query()->find($this->userId);
            $error = $this->run($row, $user);

            $error === null ? $row->touchVerified() : $row->touchFailed($error);

            AuditService::log('integration', $error === null ? 'tested' : 'test_failed', ['provider' => $row->provider, 'error' => $error], $row, causer: $user);
        } finally {
            Cache::forget(self::checkingKey((int) $row->company_id, $row->provider));
        }
    }

    /** The worker killed the job (timeout) or the recording failed: the row must not keep an old "verified". */
    public function failed(Throwable $e): void
    {
        $row = CompanyIntegration::withoutCompany()->find($this->integrationId);

        if ($row !== null) {
            $row->touchFailed('Uji koneksi terhenti sebelum selesai.');
            Cache::forget(self::checkingKey((int) $row->company_id, $row->provider));
        }
    }

    /** @return string|null the Indonesian reason the check failed, null when it passed */
    private function run(CompanyIntegration $row, ?User $user): ?string
    {
        try {
            return CompanyService::runAs((int) $row->company_id, fn (): ?string => match ($row->provider) {
                'midtrans' => app(MidtransGateway::class)->check($row),
                'whatsapp' => app(WhatsAppCloudClient::class)->check($row),
                'radius' => $row->hostRefusal() ?? app(RadiusDirectory::class)->check($row),
                'mail' => $user === null
                    ? 'Pengguna yang menguji tidak ditemukan.'
                    : $row->hostRefusal() ?? CompanyMailChannel::check($row, $user),
                default => throw new LogicException("Unknown integration provider {$row->provider}."),
            });
        } catch (Throwable $e) {
            // The class name only: an exception message of a client may quote a URL or a credential.
            Log::warning('integrations.check_crashed', ['company_id' => $row->company_id, 'provider' => $row->provider, 'exception' => class_basename($e)]);

            return 'Uji koneksi gagal karena galat tak terduga.';
        }
    }
}
