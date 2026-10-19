<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\Core\CompanyIntegration;
use App\Services\Core\AuditService;
use Illuminate\Console\Command;

/**
 * The one-off move from the install-wide environment to a company's own integrations: the owner runs it once per company
 * (DEMO, an ISP) after the migration, and from then on the app reads the rows, not MIDTRANS_*, WHATSAPP_* or RADIUS_*. Mail
 * is never imported, its environment stays the platform sender. Additive: it only writes `company_integrations`, and it
 * prints masked values, never a secret.
 */
class ImportIntegrationsFromEnvCommand extends Command
{
    use FindsCompany;

    private const PROVIDERS = ['midtrans', 'whatsapp', 'radius'];

    /** Why a provider has nothing to import, as the output says it. */
    private const NOTHING_TO_IMPORT = [
        'midtrans' => 'MIDTRANS_SERVER_KEY is empty',
        'whatsapp' => 'WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID is empty',
        'radius' => 'RADIUS_ENABLED is off',
    ];

    protected $signature = 'inbils:integrations:import-env
        {--company= : Company code (required)}
        {--provider=* : midtrans, whatsapp or radius; default all three}
        {--force : Overwrite an existing row}
        {--dry-run : Print what would be written}';

    protected $description = 'Copy the Midtrans, WhatsApp and RADIUS values of the environment into the integrations of one company.';

    public function handle(): int
    {
        if (! filled($this->option('company'))) {
            $this->error('The --company option is required.');

            return self::FAILURE;
        }

        $company = $this->findCompany((string) $this->option('company'));

        if ($company === null) {
            $this->error('Company not found.');

            return self::FAILURE;
        }

        $providers = array_values(array_unique($this->option('provider') ?: self::PROVIDERS));

        if (($unknown = array_diff($providers, self::PROVIDERS)) !== []) {
            $this->error('Unknown provider: '.implode(', ', $unknown).'. Use midtrans, whatsapp or radius.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $failed = false;

        foreach ($providers as $provider) {
            $values = match ($provider) {
                'midtrans' => $this->midtrans(),
                'whatsapp' => $this->whatsapp(),
                default => $this->radius(), // the providers were checked above: radius is the one left
            };

            if ($values === null) {
                $this->line("{$provider}: skipped, ".self::NOTHING_TO_IMPORT[$provider].'.');

                continue;
            }

            $existing = CompanyIntegration::withoutCompany()->where('company_id', $company->id)->where('provider', $provider)->first();

            if ($existing !== null && ! $this->option('force')) {
                $this->error("{$provider}: a row exists; rerun with --force to overwrite.");
                $failed = true;

                continue;
            }

            $row = $existing ?? new CompanyIntegration(['company_id' => $company->id, 'provider' => $provider]);
            $row->fill([...$values, 'is_enabled' => true])->forceFill(['verified_at' => null, 'last_error' => null, 'last_checked_at' => null]);

            if (! $dryRun) {
                $row->save();
                AuditService::log('integration', 'imported_from_env', ['provider' => $provider], $row);
            }

            $this->report($row, $dryRun);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{credentials: array<string, string>, settings: array<string, mixed>, external_id: null}|null */
    private function midtrans(): ?array
    {
        $config = config('services.midtrans');

        if (! filled($config['server_key'] ?? null)) {
            return null;
        }

        return [
            'credentials' => ['server_key' => (string) $config['server_key']],
            'settings' => array_filter([
                'is_production' => (bool) $config['is_production'],
                'allow_sandbox_in_production' => (bool) $config['allow_sandbox_in_production'],
                'base_url' => $config['base_url'] ?: null,
            ], fn (mixed $value): bool => $value !== null),
            'external_id' => null,
        ];
    }

    /** @return array{credentials: array<string, string>, settings: array<never, never>, external_id: string}|null */
    private function whatsapp(): ?array
    {
        $config = config('services.whatsapp');

        if (! filled($config['access_token'] ?? null) || ! filled($config['phone_number_id'] ?? null)) {
            return null;
        }

        return [
            'credentials' => array_filter([
                'access_token' => $config['access_token'],
                'app_secret' => $config['app_secret'],
                'webhook_verify_token' => $config['webhook_verify_token'],
            ], filled(...)),
            'settings' => [],
            'external_id' => (string) $config['phone_number_id'],
        ];
    }

    /** @return array{credentials: array<string, string>, settings: array<string, mixed>, external_id: null}|null */
    private function radius(): ?array
    {
        if (! config('services.radius.enabled')) {
            return null;
        }

        $connection = config('database.connections.radius');

        return [
            'credentials' => array_filter(['password' => $connection['password'] ?? null], filled(...)),
            'settings' => [
                'host' => (string) $connection['host'],
                'port' => (int) $connection['port'],
                'database' => (string) $connection['database'],
                'username' => (string) $connection['username'],
            ],
            'external_id' => null,
        ];
    }

    /** What was (or would be) written: plain values as they are, secrets as CompanyIntegration::masked() shows them. */
    private function report(CompanyIntegration $row, bool $dryRun): void
    {
        $this->info("{$row->provider}: ".($dryRun ? 'would import (dry run)' : 'imported'));

        foreach ($row->masked() as $key => $masked) {
            $this->line("  {$key}: ".($masked ?? 'belum diisi'));
        }

        foreach ([...$row->publicSettings(), ...($row->settings ?? [])] as $key => $value) {
            $this->line("  {$key}: ".(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }
    }
}
