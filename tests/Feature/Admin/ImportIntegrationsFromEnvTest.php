<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * inbils:integrations:import-env: the one-off copy of the install-wide environment into one company's integrations.
 * Additive, audited, masked in its output, and mail is never part of it.
 */
class ImportIntegrationsFromEnvTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['code' => 'DEMO']);
        config([
            'services.midtrans' => [
                'merchant_id' => 'G000000000', 'client_key' => 'SB-Mid-client-sentinel-0001',
                'server_key' => 'SB-Mid-server-sentinel-AAAA1111', 'is_production' => false,
                'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911', 'notification_url' => null,
            ],
            'services.whatsapp.access_token' => 'EAAG-token-sentinel-BBBB2222',
            'services.whatsapp.phone_number_id' => '109876543210987',
            'services.whatsapp.app_secret' => 'app-secret-sentinel-CCCC3333',
            'services.whatsapp.webhook_verify_token' => 'verify-sentinel-DDDD4444',
            'services.radius.enabled' => true,
            'database.connections.radius.host' => 'radius-db.lan',
            'database.connections.radius.port' => '3307',
            'database.connections.radius.database' => 'radius_demo',
            'database.connections.radius.username' => 'rad_user',
            'database.connections.radius.password' => 'radius-secret-sentinel-EEEE5555',
            'mail.mailers.smtp.host' => 'smtp.platform.test',
        ]);
    }

    private function row(string $provider): ?CompanyIntegration
    {
        return CompanyIntegration::withoutCompany()->where('company_id', $this->company->id)->where('provider', $provider)->first();
    }

    public function test_it_imports_masked_and_audited(): void
    {
        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO'])
            ->expectsOutputToContain('midtrans: imported')
            ->expectsOutputToContain('server_key: tersimpan (••••1111)')
            ->expectsOutputToContain('whatsapp: imported')
            ->expectsOutputToContain('access_token: tersimpan (••••2222)')
            ->expectsOutputToContain('phone_number_id: 109876543210987')
            ->expectsOutputToContain('radius: imported')
            ->expectsOutputToContain('password: tersimpan (••••5555)')
            ->expectsOutputToContain('host: radius-db.lan')
            ->doesntExpectOutputToContain('sentinel')
            ->assertExitCode(0);

        $midtrans = $this->row('midtrans');
        $this->assertTrue($midtrans->is_enabled);
        $this->assertNull($midtrans->verified_at);
        $this->assertSame('SB-Mid-server-sentinel-AAAA1111', $midtrans->secret('server_key'));
        $this->assertEquals(['is_production' => false, 'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911'], $midtrans->settings);

        $whatsapp = $this->row('whatsapp');
        $this->assertTrue($whatsapp->is_enabled);
        $this->assertSame('109876543210987', $whatsapp->external_id);
        $this->assertSame(['access_token' => 'EAAG-token-sentinel-BBBB2222', 'app_secret' => 'app-secret-sentinel-CCCC3333', 'webhook_verify_token' => 'verify-sentinel-DDDD4444'], $whatsapp->credentials);

        $radius = $this->row('radius');
        $this->assertTrue($radius->is_enabled);
        $this->assertEquals(['host' => 'radius-db.lan', 'port' => 3307, 'database' => 'radius_demo', 'username' => 'rad_user'], $radius->settings);
        $this->assertSame('radius-secret-sentinel-EEEE5555', $radius->secret('password'));

        $audits = Activity::query()->where('log_name', 'integration')->where('description', 'imported_from_env')->orderBy('id')->get();
        $this->assertSame(['midtrans', 'whatsapp', 'radius'], $audits->map(fn (Activity $audit): string => $audit->properties['provider'])->all());
        $this->assertSame([$this->company->id], $audits->map(fn (Activity $audit): int => (int) $audit->getAttribute('company_id'))->unique()->values()->all());
        $this->assertStringNotContainsString('sentinel', (string) json_encode($audits->pluck('properties')));
        $this->assertEquals(['provider' => 'radius'], $audits->last()->properties->all(), 'an entry names the provider and nothing else');
    }

    public function test_it_imports_only_the_providers_asked_for(): void
    {
        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO', '--provider' => ['whatsapp']])->assertExitCode(0);

        $this->assertNull($this->row('midtrans'));
        $this->assertNotNull($this->row('whatsapp'));
        $this->assertNull($this->row('radius'));
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        CompanyIntegration::query()->create([
            'company_id' => $this->company->id, 'provider' => 'midtrans', 'is_enabled' => false,
            'credentials' => ['server_key' => 'SB-Mid-server-own-0000'], 'settings' => ['is_production' => false],
        ]);

        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO'])
            ->expectsOutputToContain('midtrans: a row exists; rerun with --force to overwrite.')
            ->expectsOutputToContain('whatsapp: imported')
            ->expectsOutputToContain('radius: imported')
            ->assertExitCode(1);

        $kept = $this->row('midtrans');
        $this->assertFalse($kept->is_enabled);
        $this->assertSame('SB-Mid-server-own-0000', $kept->secret('server_key'));
        $this->assertNotNull($this->row('whatsapp'), 'the other providers carry on');
        $this->assertNotNull($this->row('radius'));
        $this->assertSame(2, Activity::query()->where('description', 'imported_from_env')->count(), 'the refused one is not audited');

        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO', '--force' => true])
            ->expectsOutputToContain('midtrans: imported')
            ->assertExitCode(0);

        $replaced = $this->row('midtrans');
        $this->assertTrue($replaced->is_enabled);
        $this->assertSame('SB-Mid-server-sentinel-AAAA1111', $replaced->secret('server_key'));
        $this->assertSame(1, CompanyIntegration::withoutCompany()->where('company_id', $this->company->id)->where('provider', 'midtrans')->count());
    }

    public function test_a_forced_import_clears_what_the_old_row_had_verified(): void
    {
        $existing = CompanyIntegration::query()->create(['company_id' => $this->company->id, 'provider' => 'whatsapp', 'external_id' => '1']);
        $existing->touchFailed('Access token ditolak Meta (kedaluwarsa atau dicabut).');

        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO', '--provider' => ['whatsapp'], '--force' => true])->assertExitCode(0);

        $row = $this->row('whatsapp');
        $this->assertNull($row->last_error);
        $this->assertNull($row->verified_at);
        $this->assertNull($row->last_checked_at);
        $this->assertSame('109876543210987', $row->external_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO', '--dry-run' => true])
            ->expectsOutputToContain('midtrans: would import (dry run)')
            ->expectsOutputToContain('server_key: tersimpan (••••1111)')
            ->expectsOutputToContain('radius: would import (dry run)')
            ->doesntExpectOutputToContain('sentinel')
            ->assertExitCode(0);

        $this->assertSame(0, CompanyIntegration::withoutCompany()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'integration')->count());
    }

    public function test_mail_is_never_imported(): void
    {
        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO'])->assertExitCode(0);

        $this->assertNull($this->row('mail'), 'the mail environment stays the platform sender');
        $this->assertSame(['midtrans', 'radius', 'whatsapp'], CompanyIntegration::withoutCompany()->orderBy('provider')->pluck('provider')->all());

        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO', '--provider' => ['mail']])
            ->expectsOutputToContain('Unknown provider: mail')
            ->assertExitCode(1);
        $this->assertNull($this->row('mail'));
    }

    public function test_a_provider_without_environment_values_is_skipped(): void
    {
        config(['services.midtrans.server_key' => null, 'services.whatsapp.phone_number_id' => '', 'services.radius.enabled' => false]);

        $this->artisan('inbils:integrations:import-env', ['--company' => 'DEMO'])
            ->expectsOutputToContain('midtrans: skipped, MIDTRANS_SERVER_KEY is empty.')
            ->expectsOutputToContain('whatsapp: skipped, WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID is empty.')
            ->expectsOutputToContain('radius: skipped, RADIUS_ENABLED is off.')
            ->assertExitCode(0);

        $this->assertSame(0, CompanyIntegration::withoutCompany()->count());
    }

    public function test_an_unknown_or_missing_company_fails(): void
    {
        $this->artisan('inbils:integrations:import-env', ['--company' => 'NOPE'])
            ->expectsOutputToContain('Company not found.')
            ->assertExitCode(1);

        $this->artisan('inbils:integrations:import-env')
            ->expectsOutputToContain('The --company option is required.')
            ->assertExitCode(1);

        $this->assertSame(0, CompanyIntegration::withoutCompany()->count());
    }
}
