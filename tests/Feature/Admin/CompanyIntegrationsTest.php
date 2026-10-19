<?php

namespace Tests\Feature\Admin;

use App\Jobs\TestCompanyIntegration;
use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Services\MidtransGateway;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use stdClass;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Pengaturan → Integrasi: who may open it, what the page may show (never a stored secret), what a save needs (a fresh
 * authenticator code, a valid host, the required fields) and what it leaves behind (an audit entry without values), and
 * the queued "Uji koneksi". The checks of Midtrans, WhatsApp and RADIUS belong to the provider slices: here they are
 * replaced, and the mail check runs against its real code.
 */
class CompanyIntegrationsTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    /** @var array{secret: string, codes: list<string>} */
    private array $twoFactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 26, 10, 0, 0, 'UTC'));
        $this->buildGroup();
        $this->admin = $this->groupUser($this->head);
        $this->twoFactor = $this->enrollTwoFactor($this->admin);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $attributes */
    private function integration(string $provider, array $attributes = [], bool $forOtherCompany = false): CompanyIntegration
    {
        // forceFill: the status columns (verified_at, last_error, last_checked_at) are not fillable.
        $row = (new CompanyIntegration())->forceFill(['company_id' => ($forOtherCompany ? $this->subA : $this->head)->id, 'provider' => $provider, ...$attributes]);
        $row->save();

        return $row;
    }

    /**
     * One save with the next authenticator code: the code of the enrolment and every earlier one is spent, and a code
     * is good for one 30-second step.
     *
     * @param  array<string, mixed>  $fields
     */
    private function save(string $provider, array $fields, ?User $as = null): TestResponse
    {
        $this->travel(31)->seconds();

        return $this->actingAs($as ?? $this->admin)->put(route('admin.company.integrations.update', $provider), ['code' => $this->totp($this->twoFactor['secret']), ...$fields]);
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.company.integrations.edit'));
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function midtrans(array $over = []): array
    {
        return ['is_enabled' => true, 'server_key' => 'SB-Mid-server-aaaabbbbccccdddd', 'is_production' => false, ...$over];
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function whatsapp(array $over = []): array
    {
        return [
            'is_enabled' => true,
            'phone_number_id' => '109876543210987',
            'access_token' => 'EAAG-access-sentinel-'.str_repeat('a', 24),
            'app_secret' => 'app-secret-sentinel-0001',
            'webhook_verify_token' => 'verify-token-sentinel-0001',
            ...$over,
        ];
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function mail(array $over = []): array
    {
        return [
            'is_enabled' => true,
            'host' => 'smtp.isp.test',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'billing',
            'password' => 'smtp-secret-sentinel-0001',
            'from_address' => 'billing@isp.test',
            'from_name' => 'ISP Billing',
            ...$over,
        ];
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function radius(array $over = []): array
    {
        return ['is_enabled' => true, 'host' => 'radius.isp.test', 'port' => 3306, 'database' => 'radius', 'username' => 'inbils', 'password' => 'radius-secret-sentinel-0001', ...$over];
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }

    // ---------------------------------------------------------------------------------------------
    // The page
    // ---------------------------------------------------------------------------------------------

    public function test_the_page_needs_the_integrations_permission(): void
    {
        $this->page()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Company/Integrations', false));

        foreach (['manager', 'staff', 'technician', 'customer'] as $role) {
            $user = $this->groupUser($this->head, $role);

            $this->actingAs($user)->get(route('admin.company.integrations.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('admin.company.integrations.update', 'midtrans'), ['code' => '123456', ...$this->midtrans()])->assertForbidden();
            $this->actingAs($user)->post(route('admin.company.integrations.test', 'midtrans'))->assertForbidden();
        }

        $staff = $this->groupUser($this->head, 'staff');
        $staff->givePermissionTo('company.integrations.manage');

        $this->actingAs($staff)->get(route('admin.company.integrations.edit'))->assertOk();
        $this->assertSame(0, CompanyIntegration::query()->count());
    }

    public function test_an_unknown_provider_is_a_404(): void
    {
        $this->actingAs($this->admin)->put('/admin/company/integrations/paypal', ['code' => '123456', 'is_enabled' => false])->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/company/integrations/paypal/test')->assertNotFound();
    }

    public function test_the_page_never_contains_a_stored_secret(): void
    {
        $secrets = [
            'server_key' => 'SB-Mid-server-sentinel-AAAA1111',
            'access_token' => 'EAAG-token-sentinel-BBBB2222',
            'app_secret' => 'app-secret-sentinel-CCCC3333',
            'webhook_verify_token' => 'verify-sentinel-DDDD4444',
            'smtp' => 'smtp-secret-sentinel-EEEE5555',
            'radius' => 'radius-secret-sentinel-FFFF6666',
        ];
        $this->integration('midtrans', [
            'credentials' => ['server_key' => $secrets['server_key']],
            'settings' => ['is_production' => false, 'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911'],
            'is_enabled' => true,
        ]);
        $this->integration('whatsapp', [
            'credentials' => ['access_token' => $secrets['access_token'], 'app_secret' => $secrets['app_secret'], 'webhook_verify_token' => $secrets['webhook_verify_token']],
            'external_id' => '109876543210987',
        ]);
        $this->integration('mail', ['credentials' => ['password' => $secrets['smtp']], 'settings' => ['host' => 'smtp.isp.test', 'port' => 587, 'transport' => 'array']]);
        $this->integration('radius', ['credentials' => ['password' => $secrets['radius']], 'settings' => ['host' => 'radius.isp.test']]);

        $response = $this->page()->assertOk();
        $props = (string) json_encode($response->viewData('page')['props']);

        foreach ($secrets as $name => $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), "the page holds the {$name} secret");
        }
        foreach (['credentials', 'allow_sandbox_in_production', 'base_url', 'transport', '18911'] as $internal) {
            $this->assertStringNotContainsString($internal, $props, "the props hold the internal {$internal}");
        }

        $response->assertInertia(fn (Assert $page) => $page
            ->where('integrations.midtrans.secrets', ['server_key' => 'tersimpan (••••1111)'])
            ->where('integrations.midtrans.settings', ['is_production' => false])
            ->where('integrations.whatsapp.secrets', ['access_token' => 'tersimpan (••••2222)', 'app_secret' => 'tersimpan (••••3333)', 'webhook_verify_token' => 'tersimpan (••••4444)'])
            ->where('integrations.whatsapp.settings', ['phone_number_id' => '109876543210987'])
            ->where('integrations.mail.secrets', ['password' => 'tersimpan (••••5555)'])
            ->where('integrations.mail.settings', ['host' => 'smtp.isp.test', 'port' => 587, 'scheme' => 'smtp', 'username' => null, 'from_address' => null, 'from_name' => null])
            ->where('integrations.radius.secrets', ['password' => 'tersimpan (••••6666)'])
            ->where('integrations.radius.settings', ['host' => 'radius.isp.test', 'port' => 3306, 'database' => null, 'username' => null]));
    }

    public function test_the_page_props_are_the_contract_of_the_screen(): void
    {
        $this->integration('midtrans', ['is_enabled' => true, 'verified_at' => now()->subMinute(), 'last_checked_at' => now()->subMinute()]);
        $this->integration('mail', ['last_error' => 'Server SMTP tidak dapat dihubungi.', 'last_checked_at' => now()]);
        Cache::put(TestCompanyIntegration::checkingKey($this->head->id, 'whatsapp'), 1, 60);
        Cache::forever('push:last_sent_at', '2026-10-25T09:30:00+00:00');
        config(['firebase.projects.app.credentials' => '/run/secrets/firebase.json', 'mail.from.address' => 'platform@inbils.test']);

        $response = $this->page();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('integrations', 4)
            ->where('integrations.midtrans', [
                'provider' => 'midtrans', 'state' => 'verified', 'is_enabled' => true, 'checking' => false,
                'verified_at' => '2026-10-26T09:59:00+00:00', 'last_checked_at' => '2026-10-26T09:59:00+00:00', 'last_error' => null,
                'settings' => ['is_production' => false], 'secrets' => ['server_key' => null],
            ])
            ->where('integrations.whatsapp.state', 'empty')
            ->where('integrations.whatsapp.checking', true)
            ->where('integrations.whatsapp.is_enabled', false)
            ->where('integrations.mail.state', 'failed')
            ->where('integrations.mail.last_error', 'Server SMTP tidak dapat dihubungi.')
            ->where('integrations.radius.state', 'empty')
            ->where('webhooks', ['midtrans' => url('/webhooks/midtrans/'.$this->head->public_id), 'whatsapp' => url('/webhooks/whatsapp')])
            ->where('firebase', ['configured' => true, 'last_sent_at' => '2026-10-25T09:30:00+00:00'])
            ->where('mailFallback', ['from_address' => 'platform@inbils.test'])
            ->where('twoFactorEnabled', true));

        $this->assertSame(['midtrans', 'whatsapp', 'mail', 'radius'], array_keys($response->viewData('page')['props']['integrations']), 'the cards come in the order of the screen');
    }

    public function test_a_company_only_ever_sees_and_saves_its_own_rows(): void
    {
        $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-of-sub-a-0001'], 'settings' => ['is_production' => false], 'is_enabled' => true], forOtherCompany: true);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('integrations.midtrans.state', 'empty')
            ->where('integrations.midtrans.secrets.server_key', null));

        $this->save('midtrans', $this->midtrans(['server_key' => 'SB-Mid-server-of-head-0002']))->assertSessionHasNoErrors();

        $this->assertSame(2, CompanyIntegration::withoutCompany()->count());
        $this->assertSame('SB-Mid-server-of-sub-a-0001', CompanyIntegration::withoutCompany()->where('company_id', $this->subA->id)->sole()->secret('server_key'));
        $this->assertSame('SB-Mid-server-of-head-0002', CompanyIntegration::withoutCompany()->where('company_id', $this->head->id)->sole()->secret('server_key'));
    }

    // ---------------------------------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------------------------------

    public function test_saving_requires_a_valid_authenticator_code(): void
    {
        $route = route('admin.company.integrations.update', 'midtrans');

        $this->actingAs($this->admin)->put($route, $this->midtrans())->assertSessionHasErrors(['code']);
        $this->actingAs($this->admin)->put($route, ['code' => '000000', ...$this->midtrans()])
            ->assertSessionHasErrors(['code' => 'Kode autentikasi salah atau sudah dipakai.']);
        $this->assertSame(0, CompanyIntegration::query()->count(), 'a refused save writes nothing');

        $this->travel(31)->seconds();
        $code = $this->totp($this->twoFactor['secret']);
        $this->actingAs($this->admin)->put($route, ['code' => $code, ...$this->midtrans()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Integrasi Midtrans disimpan.');
        $this->assertSame('SB-Mid-server-aaaabbbbccccdddd', CompanyIntegration::query()->sole()->secret('server_key'));

        $this->actingAs($this->admin)->put($route, ['code' => $code, ...$this->midtrans(['server_key' => 'SB-Mid-server-replayed-0000'])])
            ->assertSessionHasErrors(['code' => 'Kode autentikasi salah atau sudah dipakai.']);
        $this->assertSame('SB-Mid-server-aaaabbbbccccdddd', CompanyIntegration::query()->sole()->secret('server_key'), 'the spent code saves nothing');
    }

    public function test_a_recovery_code_does_for_one_save(): void
    {
        $route = route('admin.company.integrations.update', 'midtrans');
        $recovery = $this->twoFactor['codes'][0];

        $this->actingAs($this->admin)->put($route, ['code' => $recovery, ...$this->midtrans(['server_key' => 'SB-Mid-server-by-recovery-1'])])->assertSessionHasNoErrors();
        $this->assertSame('SB-Mid-server-by-recovery-1', CompanyIntegration::query()->sole()->secret('server_key'));

        $this->actingAs($this->admin)->put($route, ['code' => $recovery, ...$this->midtrans(['server_key' => 'SB-Mid-server-by-recovery-2'])])->assertSessionHasErrors(['code']);
        $this->assertSame('SB-Mid-server-by-recovery-1', CompanyIntegration::query()->sole()->secret('server_key'), 'a recovery code is spent as well');
    }

    public function test_a_field_error_does_not_spend_the_code(): void
    {
        $route = route('admin.company.integrations.update', 'mail');
        $this->travel(31)->seconds();
        $code = $this->totp($this->twoFactor['secret']);

        $this->actingAs($this->admin)->put($route, ['code' => $code, ...$this->mail(['port' => 70000])])->assertSessionHasErrors(['port']);
        $this->assertSame(0, CompanyIntegration::query()->count());

        $this->actingAs($this->admin)->put($route, ['code' => $code, ...$this->mail()])->assertSessionHasNoErrors();
        $this->assertSame('smtp.isp.test', CompanyIntegration::query()->sole()->setting('host'));
    }

    public function test_a_refused_save_does_not_flash_secrets_or_the_code_into_the_session(): void
    {
        $this->actingAs($this->admin)->from('/admin/company/integrations')
            ->put(route('admin.company.integrations.update', 'whatsapp'), ['code' => '000000', ...$this->whatsapp()])
            ->assertSessionHasErrors(['code']);

        $session = (string) json_encode(session()->all());

        $this->assertStringContainsString('109876543210987', $session, 'a plain field is flashed back, so the check means something');

        foreach (['app-secret-sentinel-0001', 'verify-token-sentinel-0001', 'EAAG-access-sentinel-', '000000'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $session);
        }
    }

    public function test_saving_without_two_factor_is_refused_with_a_profile_hint(): void
    {
        $plain = $this->groupUser($this->head);

        $this->actingAs($plain)->put(route('admin.company.integrations.update', 'midtrans'), ['code' => '123456', ...$this->midtrans()])
            ->assertSessionHasErrors(['code' => 'Aktifkan autentikasi dua langkah di Profil sebelum menyimpan integrasi.']);
        $this->assertSame(0, CompanyIntegration::query()->count());

        $this->actingAs($plain)->get(route('admin.company.integrations.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', false));
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-kept-0001'], 'settings' => ['is_production' => false]]);

        $this->save('midtrans', $this->midtrans(['server_key' => '']))->assertSessionHasNoErrors();
        $this->assertSame('SB-Mid-server-kept-0001', CompanyIntegration::query()->sole()->secret('server_key'));
        $this->assertTrue(CompanyIntegration::query()->sole()->is_enabled, 'it could be enabled on the stored key');

        $this->save('midtrans', ['is_enabled' => true, 'is_production' => false])->assertSessionHasNoErrors();
        $this->assertSame('SB-Mid-server-kept-0001', CompanyIntegration::query()->sole()->secret('server_key'), 'an absent secret keeps it too');

        $this->save('midtrans', $this->midtrans(['server_key' => 'SB-Mid-server-new-0002']))->assertSessionHasNoErrors();
        $this->assertSame('SB-Mid-server-new-0002', CompanyIntegration::query()->sole()->secret('server_key'), 'a filled one replaces it');

        $this->save('whatsapp', $this->whatsapp())->assertSessionHasNoErrors();
        $this->save('whatsapp', $this->whatsapp(['access_token' => null, 'app_secret' => null, 'webhook_verify_token' => null, 'phone_number_id' => '555000111']))->assertSessionHasNoErrors();

        $whatsapp = CompanyIntegration::query()->where('provider', 'whatsapp')->sole();
        $this->assertSame('app-secret-sentinel-0001', $whatsapp->secret('app_secret'));
        $this->assertSame('verify-token-sentinel-0001', $whatsapp->secret('webhook_verify_token'));
        $this->assertStringStartsWith('EAAG-access-sentinel-', (string) $whatsapp->secret('access_token'));
        $this->assertSame('555000111', $whatsapp->external_id, 'the number is a plain field: it is replaced');
    }

    public function test_the_rest_of_the_settings_survive_a_save(): void
    {
        $this->integration('midtrans', [
            'credentials' => ['server_key' => 'SB-Mid-server-kept-0001'],
            'settings' => ['is_production' => false, 'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911'],
        ]);

        $this->save('midtrans', ['is_enabled' => true, 'is_production' => false])->assertSessionHasNoErrors();

        $this->assertEquals(
            ['is_production' => false, 'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911'],
            CompanyIntegration::query()->sole()->settings,
            'what only the import writes is not the screen\'s to drop',
        );
    }

    public function test_a_changed_field_clears_the_verification(): void
    {
        $row = $this->integration('mail', [
            'credentials' => ['password' => 'smtp-secret-sentinel-0001'],
            'settings' => ['host' => 'smtp.isp.test', 'port' => 587, 'scheme' => 'smtp', 'username' => 'billing', 'from_address' => 'billing@isp.test', 'from_name' => 'ISP Billing'],
            'is_enabled' => true,
        ]);
        $row->touchVerified();

        // The same values again, a switch flipped and the secret left blank: nothing that was verified changed.
        $this->save('mail', $this->mail(['password' => '', 'is_enabled' => false, 'port' => '587']))->assertSessionHasNoErrors();
        $row->refresh();
        $this->assertFalse($row->is_enabled);
        $this->assertNotNull($row->verified_at, 'a switch alone keeps the verification');
        $this->assertSame('verified', CompanyIntegration::stateOf($row));

        $this->save('mail', $this->mail(['password' => '', 'host' => 'smtp2.isp.test']))->assertSessionHasNoErrors();
        $row->refresh();
        $this->assertNull($row->verified_at, 'a changed host does not');
        $this->assertSame('filled', CompanyIntegration::stateOf($row));

        $row->touchFailed('Login SMTP ditolak.');
        $this->save('mail', $this->mail(['host' => 'smtp2.isp.test', 'password' => 'smtp-secret-sentinel-0002']))->assertSessionHasNoErrors();
        $row->refresh();
        $this->assertNull($row->last_error, 'a new secret clears the last error');
        $this->assertSame('smtp-secret-sentinel-0002', $row->secret('password'));

        $row->touchVerified();
        $this->save('mail', $this->mail(['host' => 'smtp2.isp.test', 'password' => '', 'port' => 465]))->assertSessionHasNoErrors();
        $this->assertNull($row->refresh()->verified_at, 'a changed port does not either');
    }

    public function test_saving_writes_an_audit_row_without_values(): void
    {
        $this->save('whatsapp', $this->whatsapp())->assertSessionHasNoErrors();

        $audit = Activity::query()->where('log_name', 'integration')->where('description', 'saved')->sole();

        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame(CompanyIntegration::class, $audit->subject_type);
        $this->assertSame($this->head->id, (int) $audit->getAttribute('company_id'));
        $this->assertEquals(
            ['provider' => 'whatsapp', 'fields' => ['access_token', 'app_secret', 'webhook_verify_token', 'phone_number_id'], 'is_enabled' => true],
            $audit->properties->all(),
        );

        foreach (['EAAG-access-sentinel-', 'app-secret-sentinel-0001', 'verify-token-sentinel-0001', '109876543210987'] as $value) {
            $this->assertStringNotContainsString($value, (string) json_encode($audit->properties), 'an audit entry names the fields, never a value');
        }

        $this->save('whatsapp', $this->whatsapp(['is_enabled' => false, 'access_token' => '', 'app_secret' => '', 'webhook_verify_token' => '']))->assertSessionHasNoErrors();

        $latest = Activity::query()->where('log_name', 'integration')->where('description', 'saved')->latest('id')->firstOrFail();
        $this->assertSame([], $latest->properties['fields'], 'a save that changed nothing names no field');
        $this->assertFalse($latest->properties['is_enabled']);
    }

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function requiredWhenEnabled(): array
    {
        return [
            'midtrans' => ['midtrans', ['is_production' => false], ['server_key']],
            'whatsapp' => ['whatsapp', [], ['phone_number_id', 'access_token', 'app_secret', 'webhook_verify_token']],
            'mail' => ['mail', [], ['host', 'from_address']],
            'radius' => ['radius', [], ['host', 'database', 'username']],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $missing
     */
    #[DataProvider('requiredWhenEnabled')]
    public function test_enabling_requires_the_required_fields(string $provider, array $fields, array $missing): void
    {
        $this->save($provider, ['is_enabled' => true, ...$fields])->assertSessionHasErrors($missing);
        $this->assertSame(0, CompanyIntegration::query()->count(), 'an enabled card with holes is not stored');

        // Switched off, the same card may be saved half empty.
        $this->save($provider, ['is_enabled' => false, ...$fields])->assertSessionHasNoErrors();
        $this->assertFalse(CompanyIntegration::query()->sole()->is_enabled);
    }

    public function test_enabling_accepts_the_secrets_that_are_already_stored(): void
    {
        $this->integration('whatsapp', ['credentials' => ['access_token' => 'EAAG-stored-0001', 'app_secret' => 'stored-app-secret', 'webhook_verify_token' => 'stored-verify-token-1']]);

        $this->save('whatsapp', ['is_enabled' => true, 'phone_number_id' => '109876543210987'])->assertSessionHasNoErrors();
        $this->assertTrue(CompanyIntegration::query()->sole()->is_enabled);

        $this->save('whatsapp', $this->whatsapp(['webhook_verify_token' => 'too-short']))->assertSessionHasErrors(['webhook_verify_token']);
        $this->save('whatsapp', $this->whatsapp(['phone_number_id' => '12']))->assertSessionHasErrors(['phone_number_id']);
    }

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function midtransModes(): array
    {
        return [
            'a sandbox key in sandbox mode' => [['server_key' => 'SB-Mid-server-aaaa', 'is_production' => false], null],
            'a production key in production mode' => [['server_key' => 'Mid-server-aaaa', 'is_production' => true], null],
            'a sandbox key in production mode' => [['server_key' => 'SB-Mid-server-aaaa', 'is_production' => true], 'Server key sandbox (SB-Mid-server-) tidak bisa dipakai di mode produksi.'],
            'a production key in sandbox mode' => [['server_key' => 'Mid-server-aaaa', 'is_production' => false], 'Server key produksi (Mid-server-) tidak bisa dipakai di mode sandbox.'],
            'not a Midtrans server key' => [['server_key' => 'SB-Mid-client-aaaa', 'is_production' => false], 'server key'],
        ];
    }

    /** @param  array<string, mixed>  $fields */
    #[DataProvider('midtransModes')]
    public function test_the_midtrans_key_prefix_must_match_the_mode(array $fields, ?string $error): void
    {
        $response = $this->save('midtrans', ['is_enabled' => true, ...$fields]);

        if ($error === null) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($fields['server_key'], CompanyIntegration::query()->sole()->secret('server_key'));

            return;
        }

        $response->assertSessionHasErrors('server_key');
        $this->assertStringContainsString($error, (string) session('errors')->first('server_key'));
        $this->assertSame(0, CompanyIntegration::query()->count());
    }

    public function test_a_stored_key_must_match_a_changed_mode_too(): void
    {
        $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-stored-0001'], 'settings' => ['is_production' => false]]);

        $this->save('midtrans', ['is_enabled' => true, 'is_production' => true])
            ->assertSessionHasErrors(['is_production' => 'Server key sandbox (SB-Mid-server-) tidak bisa dipakai di mode produksi.']);
        $this->assertFalse(CompanyIntegration::query()->sole()->setting('is_production'));

        $this->save('midtrans', ['is_enabled' => true, 'is_production' => true, 'server_key' => 'Mid-server-live-0001'])->assertSessionHasNoErrors();
        $this->assertTrue(CompanyIntegration::query()->sole()->setting('is_production'));
    }

    /** @return array<string, array{string, string}> */
    public static function blockedHosts(): array
    {
        $cases = [];

        foreach (['mail', 'radius'] as $provider) {
            foreach (['169.254.169.254', '169.254.0.7', 'fe80::1', '0.0.0.0', '224.0.0.1', '2852039166', '::ffff:169.254.169.254', 'http://smtp.isp.test', 'smtp.isp.test:25', 'bad host'] as $host) {
                $cases["{$provider} {$host}"] = [$provider, $host];
            }
        }

        return $cases;
    }

    #[DataProvider('blockedHosts')]
    public function test_hosts_in_blocked_ranges_are_refused(string $provider, string $host): void
    {
        $this->save($provider, $this->{$provider}(['host' => $host]))->assertSessionHasErrors(['host']);
        $this->assertSame(0, CompanyIntegration::query()->count());

        $this->save($provider, $this->{$provider}(['host' => '192.168.1.10']))->assertSessionHasNoErrors();
        $this->assertSame('192.168.1.10', CompanyIntegration::query()->sole()->setting('host'), 'the ISP LAN is where these servers live');
    }

    public function test_the_field_limits_hold(): void
    {
        $this->save('mail', $this->mail(['scheme' => 'tls']))->assertSessionHasErrors(['scheme']);
        $this->save('mail', $this->mail(['from_address' => 'not-an-address']))->assertSessionHasErrors(['from_address']);
        $this->save('mail', $this->mail(['port' => 0]))->assertSessionHasErrors(['port']);
        $this->save('radius', $this->radius(['database' => 'radius; DROP']))->assertSessionHasErrors(['database']);
        $this->save('radius', $this->radius(['username' => str_repeat('u', 65)]))->assertSessionHasErrors(['username']);
        $this->save('midtrans', ['is_production' => false])->assertSessionHasErrors(['is_enabled']);
        $this->save('midtrans', ['is_enabled' => true])->assertSessionHasErrors(['is_production']);
        $this->save('midtrans', $this->midtrans(['is_enabled' => 'maybe']))->assertSessionHasErrors(['is_enabled']);

        $this->assertSame(0, CompanyIntegration::query()->count());
    }

    public function test_saving_is_throttled_and_the_test_button_counts_on_its_own(): void
    {
        Queue::fake();
        $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-aaaabbbb'], 'settings' => ['is_production' => false]]);
        $update = route('admin.company.integrations.update', 'midtrans');
        $test = route('admin.company.integrations.test', 'midtrans');

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($this->admin)->put($update, ['code' => '000000', ...$this->midtrans()])->assertSessionHasErrors(['code']);
        }

        $this->actingAs($this->admin)->put($update, ['code' => '000000', ...$this->midtrans()])->assertStatus(429);

        // Six refused saves used up the save budget only.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($this->admin)->post($test)->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)->post($test)->assertStatus(429);
    }

    // ---------------------------------------------------------------------------------------------
    // Uji koneksi
    // ---------------------------------------------------------------------------------------------

    public function test_the_test_button_queues_one_check_and_marks_it_running(): void
    {
        Queue::fake();
        $row = $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-aaaabbbb'], 'settings' => ['is_production' => false]]);
        $route = route('admin.company.integrations.test', 'midtrans');

        $this->actingAs($this->admin)->post($route)->assertSessionHas('success', 'Uji koneksi Midtrans berjalan.');
        Queue::assertPushed(TestCompanyIntegration::class, 1);
        Queue::assertPushed(TestCompanyIntegration::class, fn (TestCompanyIntegration $job): bool => $job->integrationId === $row->id && $job->userId === $this->admin->id && $job->tries === 1 && $job->timeout === 20);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('integrations.midtrans.checking', true)
            ->where('integrations.whatsapp.checking', false));

        $this->actingAs($this->admin)->post($route)->assertSessionHas('warning', 'Uji koneksi Midtrans sedang berjalan.');
        Queue::assertPushed(TestCompanyIntegration::class, 1);

        // Another provider is a check of its own.
        $this->integration('radius', ['settings' => ['host' => '10.0.0.5']]);
        $this->actingAs($this->admin)->post(route('admin.company.integrations.test', 'radius'))->assertSessionHas('success', 'Uji koneksi RADIUS berjalan.');
        Queue::assertPushed(TestCompanyIntegration::class, 2);
    }

    public function test_a_card_that_was_never_saved_cannot_be_tested(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->post(route('admin.company.integrations.test', 'whatsapp'))
            ->assertSessionHasErrors(['provider' => 'Simpan pengaturan WhatsApp dulu.']);
        Queue::assertNothingPushed();

        // Another company's row is not this company's card.
        $this->integration('whatsapp', ['external_id' => '123456'], forOtherCompany: true);
        $this->actingAs($this->admin)->post(route('admin.company.integrations.test', 'whatsapp'))->assertSessionHasErrors(['provider']);
        Queue::assertNothingPushed();
    }

    public function test_the_mail_check_records_success_and_failure(): void
    {
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $row = $this->integration('mail', ['settings' => [
            'host' => '10.0.0.5', 'port' => 587, 'from_address' => 'billing@isp.test', 'from_name' => 'ISP Billing', 'transport' => 'array',
        ]]);
        $route = route('admin.company.integrations.test', 'mail');

        // The sync queue of the tests runs the job inside the request, so its outcome is there at once.
        $this->actingAs($this->admin)->post($route)->assertSessionHas('success', 'Uji koneksi Email berjalan.');

        $row->refresh();
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->last_error);
        $this->assertNotNull($row->last_checked_at);
        $this->assertCount(1, $sent);
        $message = $sent[0]->message;
        $this->assertSame('Email uji Inbils', $message->getSubject());
        $this->assertSame([$this->admin->email], array_map(fn ($address) => $address->getAddress(), $message->getTo()));
        $this->assertSame(['billing@isp.test'], array_map(fn ($address) => $address->getAddress(), $message->getFrom()));
        $this->assertStringContainsString($this->head->name, (string) $message->getTextBody());
        $this->assertSame('ondemand', $sent[0]->data['mailer'], 'through the company\'s own mailer, not the platform default');

        $audit = Activity::query()->where('log_name', 'integration')->where('description', 'tested')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame($this->head->id, (int) $audit->company_id);
        $this->assertEquals(['provider' => 'mail', 'error' => null], $audit->properties->all());
        $this->page()->assertInertia(fn (Assert $page) => $page->where('integrations.mail.checking', false)->where('integrations.mail.state', 'verified'));

        // A server that is not there: the real SMTP transport against a closed port of this machine.
        $port = $this->closedPort();
        $row->update(['settings' => ['host' => '127.0.0.1', 'port' => $port, 'from_address' => 'billing@isp.test']]);
        $this->actingAs($this->admin)->post($route)->assertSessionHas('success');

        $row->refresh();
        $this->assertNull($row->verified_at);
        $this->assertSame("Server SMTP tidak dapat dihubungi dalam 5 detik (127.0.0.1:{$port}).", $row->last_error);
        $this->assertCount(1, $sent, 'nothing was sent');
        $this->assertSame('failed', CompanyIntegration::stateOf($row));
        $failure = Activity::query()->where('log_name', 'integration')->where('description', 'test_failed')->sole();
        $this->assertSame($row->last_error, $failure->properties['error']);
    }

    public function test_an_incomplete_mail_card_says_what_is_missing_instead_of_failing_obscurely(): void
    {
        $row = $this->integration('mail', ['settings' => ['port' => 587]]);

        $this->actingAs($this->admin)->post(route('admin.company.integrations.test', 'mail'))->assertSessionHas('success');

        $this->assertSame('Isi host SMTP dan alamat pengirim dulu.', $row->refresh()->last_error);
    }

    /** @return array<string, array{string, ?string}> */
    public static function outcomes(): array
    {
        return [
            'midtrans passes' => ['midtrans', null],
            'midtrans fails' => ['midtrans', 'Server key ditolak Midtrans. Periksa key dan mode.'],
            'whatsapp passes' => ['whatsapp', null],
            'whatsapp fails' => ['whatsapp', 'Access token ditolak Meta (kedaluwarsa atau dicabut).'],
            'radius passes' => ['radius', null],
            'radius fails' => ['radius', 'Tabel FreeRADIUS tidak lengkap: radacct.'],
        ];
    }

    /** The job calls the `check()` of the provider's own class, inside the company of the row, and records what it says. */
    #[DataProvider('outcomes')]
    public function test_each_provider_check_is_routed_and_its_result_recorded(string $provider, ?string $outcome): void
    {
        $other = $this->groupUser($this->subA);
        $seen = new stdClass();
        $this->fakeCheck($provider, $outcome, $seen);
        $row = $this->integration($provider, ['settings' => ['host' => '10.0.0.5'], 'verified_at' => now()->subDay()], forOtherCompany: true);
        $this->integration($provider, ['settings' => ['host' => '10.0.0.6']]);

        TestCompanyIntegration::dispatchSync($row->id, $other->id);

        $row->refresh();
        $this->assertSame((int) $row->company_id, $seen->company, 'the check ran in the company of the row');
        $this->assertSame($outcome, $row->last_error);
        $this->assertSame($outcome === null, $row->verified_at !== null);
        $this->assertNotNull($row->last_checked_at);
        $this->assertSame(1, CompanyIntegration::withoutCompany()->where('provider', $provider)->whereNotNull('last_checked_at')->count(), 'the other company\'s row was not touched');

        $audit = Activity::query()->where('log_name', 'integration')->sole();
        $this->assertSame($outcome === null ? 'tested' : 'test_failed', $audit->description);
        $this->assertSame($outcome, $audit->properties['error']);
        $this->assertSame($other->id, $audit->causer_id);
    }

    public function test_an_unexpected_exception_is_stored_as_a_generic_failure_without_its_message(): void
    {
        Log::spy();
        $row = $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-aaaabbbb'], 'settings' => ['is_production' => false], 'verified_at' => now()->subDay()]);
        $key = TestCompanyIntegration::checkingKey($this->head->id, 'midtrans');
        Cache::put($key, 1, 60);
        $this->app->instance(MidtransGateway::class, new class() extends MidtransGateway
        {
            public function check(CompanyIntegration $integration): ?string
            {
                throw new RuntimeException('POST https://api.midtrans.com failed for SB-Mid-server-aaaabbbb');
            }
        });

        TestCompanyIntegration::dispatchSync($row->id, $this->admin->id);

        $row->refresh();
        $this->assertSame('Uji koneksi gagal karena galat tak terduga.', $row->last_error);
        $this->assertNull($row->verified_at);
        Log::shouldHaveReceived('warning')->once()->with('integrations.check_crashed', ['company_id' => $this->head->id, 'provider' => 'midtrans', 'exception' => 'RuntimeException']);
        $this->assertFalse(Cache::has($key), 'the running mark is gone even after a crash');
        $this->assertStringNotContainsString('SB-Mid-server', (string) json_encode(Activity::query()->where('log_name', 'integration')->pluck('properties')));
    }

    public function test_a_check_the_worker_gave_up_on_does_not_leave_an_old_verification(): void
    {
        $row = $this->integration('whatsapp', ['external_id' => '123456', 'verified_at' => now()->subDay()]);
        $key = TestCompanyIntegration::checkingKey($this->head->id, 'whatsapp');
        Cache::put($key, 1, 60);

        (new TestCompanyIntegration($row->id, $this->admin->id))->failed(new RuntimeException('timeout'));

        $row->refresh();
        $this->assertNull($row->verified_at);
        $this->assertSame('Uji koneksi terhenti sebelum selesai.', $row->last_error);
        $this->assertFalse(Cache::has($key));
    }

    public function test_a_row_deleted_before_the_job_ran_is_not_an_error(): void
    {
        $row = $this->integration('whatsapp', ['external_id' => '123456']);
        $row->delete();

        TestCompanyIntegration::dispatchSync($row->id, $this->admin->id);

        $this->assertSame(0, Activity::query()->where('log_name', 'integration')->count());
    }

    public function test_a_host_that_is_refused_at_check_time_is_never_contacted(): void
    {
        $this->fakeCheck('radius', null, new stdClass());
        $mail = $this->integration('mail', ['settings' => ['host' => '169.254.169.254', 'port' => 25, 'from_address' => 'a@isp.test', 'transport' => 'array']]);
        $radius = $this->integration('radius', ['settings' => ['host' => 'fe80::1']]);

        TestCompanyIntegration::dispatchSync($mail->id, $this->admin->id);
        TestCompanyIntegration::dispatchSync($radius->id, $this->admin->id);

        $this->assertSame('Host 169.254.169.254 tidak diizinkan (alamat lokal atau metadata).', $mail->refresh()->last_error);
        $this->assertSame('Host fe80::1 tidak diizinkan (alamat lokal atau metadata).', $radius->refresh()->last_error);
        $this->assertNull($radius->verified_at, 'the radius fake would have passed: it was never asked');
    }

    public function test_a_name_that_is_local_is_refused_at_check_time_outside_the_local_environments(): void
    {
        $named = $this->integration('mail', ['settings' => ['host' => 'localhost', 'from_address' => 'a@isp.test', 'transport' => 'array']]);

        $this->app->detectEnvironment(fn (): string => 'production');
        TestCompanyIntegration::dispatchSync($named->id, $this->admin->id);

        $this->assertSame('Host localhost tidak diizinkan (alamat lokal atau metadata).', $named->refresh()->last_error);
    }

    private function fakeCheck(string $provider, ?string $outcome, stdClass $seen): void
    {
        match ($provider) {
            'midtrans' => $this->app->instance(MidtransGateway::class, new class($outcome, $seen) extends MidtransGateway
            {
                public function __construct(private readonly ?string $outcome, private readonly stdClass $seen) {}

                public function check(CompanyIntegration $integration): ?string
                {
                    $this->seen->company = CompanyService::currentId();

                    return $this->outcome;
                }
            }),
            'whatsapp' => $this->app->instance(WhatsAppCloudClient::class, new class($outcome, $seen) extends WhatsAppCloudClient
            {
                public function __construct(private readonly ?string $outcome, private readonly stdClass $seen) {}

                public function check(CompanyIntegration $integration): ?string
                {
                    $this->seen->company = CompanyService::currentId();

                    return $this->outcome;
                }
            }),
            'radius' => $this->app->instance(RadiusDirectory::class, new class($outcome, $seen) extends RadiusDirectory
            {
                public function __construct(private readonly ?string $outcome, private readonly stdClass $seen) {}

                public function check(CompanyIntegration $integration): ?string
                {
                    $this->seen->company = CompanyService::currentId();

                    return $this->outcome;
                }
            }),
        };
    }
}
