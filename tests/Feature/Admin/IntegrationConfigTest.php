<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;

/**
 * Where a company's own accounts live and how they are read: encrypted at rest, masked on the way out, resolved once
 * per request or job and forgotten on every save.
 */
class IntegrationConfigTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    /** @param array<string, mixed> $attributes */
    private function integration(string $provider, array $attributes = [], ?Company $company = null): CompanyIntegration
    {
        return CompanyIntegration::query()->create(['company_id' => ($company ?? $this->company)->id, 'provider' => $provider, ...$attributes]);
    }

    /** @return int how many reads of the table $callback causes */
    private function reads(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'company_integrations')));
    }

    /**
     * Puts $credentials in the row as the table holds them after the application key changed: sealed with a key this
     * app no longer has.
     *
     * @param  array<string, string>  $credentials
     */
    private function seal(CompanyIntegration $row, array $credentials, string $key): void
    {
        $sealed = (new Encrypter($key, (string) config('app.cipher')))->encrypt((string) json_encode($credentials), false);

        DB::table('company_integrations')->where('id', $row->id)->update(['credentials' => $sealed]);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $row = $this->integration('midtrans', ['credentials' => ['server_key' => 'SB-Mid-server-sentinel-0001']]);

        $raw = (string) DB::table('company_integrations')->where('id', $row->id)->value('credentials');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('sentinel', $raw);
        $this->assertSame(['server_key' => 'SB-Mid-server-sentinel-0001'], json_decode(Crypt::decryptString($raw), true));
        $this->assertSame('SB-Mid-server-sentinel-0001', $row->fresh()->secret('server_key'));
        $this->assertArrayNotHasKey('credentials', $row->fresh()->toArray(), 'a serialised row never carries the ciphertext');
        $this->assertStringNotContainsString('sentinel', $row->fresh()->toJson());
    }

    public function test_rows_are_memoised_and_forgotten_on_save(): void
    {
        $row = $this->integration('midtrans', ['is_enabled' => true]);
        $config = app(IntegrationConfig::class);

        $this->assertSame(1, $this->reads(fn () => $config->forCompany($this->company->id, 'midtrans')), 'the first read goes to the table');
        $this->assertSame(0, $this->reads(function () use ($config): void {
            $config->forCompany($this->company->id, 'midtrans');
            $config->isEnabled('midtrans', $this->company->id);
        }), 'the next ones do not');

        $this->assertNull($config->forCompany($this->company->id, 'radius'));
        $this->assertSame(0, $this->reads(fn () => $config->forCompany($this->company->id, 'radius')), 'a missing row is remembered too');

        $row->update(['is_enabled' => false]);

        $this->assertFalse($config->isEnabled('midtrans', $this->company->id), 'a save is seen at once');

        $this->integration('radius', ['is_enabled' => true]);

        $this->assertTrue($config->isEnabled('radius', $this->company->id), 'a row created after a miss is seen at once');

        $row->delete();

        $this->assertNull($config->forCompany($this->company->id, 'midtrans'), 'so is a delete');
    }

    public function test_the_service_reads_the_company_it_is_asked_for_and_the_ambient_one_for_current(): void
    {
        $other = Company::factory()->create();
        $mine = $this->integration('whatsapp', ['is_enabled' => true]);
        $this->integration('whatsapp', ['is_enabled' => false], $other);
        $config = app(IntegrationConfig::class);

        $this->assertNull($config->current('whatsapp'), 'no company in scope, no row: nothing is guessed');
        $this->assertFalse($config->isEnabled('whatsapp'));
        $this->assertTrue($config->isEnabled('whatsapp', $this->company->id));
        $this->assertFalse($config->isEnabled('whatsapp', $other->id));

        CompanyService::runAs($this->company->id, fn () => $this->assertTrue($config->current('whatsapp')?->is($mine)));
    }

    public function test_the_service_is_scoped_so_each_job_starts_fresh(): void
    {
        $this->integration('midtrans', ['is_enabled' => true]);
        $first = app(IntegrationConfig::class);

        $this->assertSame($first, app(IntegrationConfig::class), 'one instance for the whole request');
        $this->assertTrue($first->isEnabled('midtrans', $this->company->id));

        // A write that bypasses the model (another process) is not seen by the instance that already read the row...
        DB::table('company_integrations')->update(['is_enabled' => false]);
        $this->assertTrue($first->isEnabled('midtrans', $this->company->id));

        // ...and the worker forgets the instance before the next job.
        app()->forgetScopedInstances();
        $second = app(IntegrationConfig::class);

        $this->assertNotSame($first, $second);
        $this->assertFalse($second->isEnabled('midtrans', $this->company->id));
    }

    public function test_masked_shows_only_the_last_four_of_long_secrets(): void
    {
        $row = $this->integration('whatsapp', ['credentials' => [
            'access_token' => 'EAAG'.str_repeat('x', 40).'1234',
            'app_secret' => 'twelve-chars', // the shortest secret that shows a tail
            'webhook_verify_token' => 'eleven-char', // one less shows nothing
        ]]);

        $this->assertSame([
            'access_token' => 'tersimpan (••••1234)',
            'app_secret' => 'tersimpan (••••hars)',
            'webhook_verify_token' => 'tersimpan',
        ], $row->fresh()->masked());

        $this->assertSame(['server_key' => null], $this->integration('midtrans')->masked(), 'a secret never set is null');
        $this->assertSame(['access_token' => null, 'app_secret' => null, 'webhook_verify_token' => null], (new CompanyIntegration(['provider' => 'whatsapp']))->masked());
    }

    public function test_the_state_follows_the_row_and_reads_no_secret(): void
    {
        $this->assertSame('empty', CompanyIntegration::stateOf(null));

        $row = $this->integration('mail');
        $this->assertSame('filled', CompanyIntegration::stateOf($row));

        $row->touchVerified();
        $this->assertSame('verified', CompanyIntegration::stateOf($row->fresh()));
        $this->assertNotNull($row->fresh()->last_checked_at);

        $row->touchFailed(str_repeat('galat ', 80));
        $fresh = $row->fresh();
        $this->assertSame('failed', CompanyIntegration::stateOf($fresh));
        $this->assertNull($fresh->verified_at);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $fresh->last_error), 'a long message fits the column');

        // Platform pages select these columns only: the state needs nothing else.
        $partial = CompanyIntegration::withoutCompany()->select(['id', 'company_id', 'provider', 'is_enabled', 'verified_at', 'last_error', 'last_checked_at'])->find($row->id);
        $this->assertSame('failed', CompanyIntegration::stateOf($partial));
    }

    public function test_credentials_the_key_no_longer_opens_read_as_none(): void
    {
        $secrets = ['access_token' => 'EAAG'.str_repeat('x', 40).'1234', 'app_secret' => 'app-secret-sentinel-0001', 'webhook_verify_token' => 'verify-token-sentinel-0001'];
        $row = $this->integration('whatsapp', ['credentials' => $secrets, 'external_id' => '1098765432109', 'is_enabled' => true]);
        $row->touchVerified();
        $this->assertSame('verified', CompanyIntegration::stateOf($row));

        $this->seal($row, $secrets, str_repeat('k', 32));
        $stale = CompanyIntegration::withoutCompany()->findOrFail($row->id);

        $this->assertSame([], $stale->credentials, 'it reads as no secrets and does not throw');
        $this->assertNull($stale->secret('access_token'));
        $this->assertSame(['access_token' => null, 'app_secret' => null, 'webhook_verify_token' => null], $stale->masked());
        $this->assertSame('empty', CompanyIntegration::stateOf($stale), 'what was verified can no longer be used');
        $this->assertSame(['phone_number_id' => '1098765432109'], $stale->publicSettings(), 'the plain fields are still there');
        $this->assertTrue($stale->is_enabled);

        $stale->credentials = ['access_token' => 'EAAG-new-token-0001'];
        $stale->save();

        $this->assertSame('EAAG-new-token-0001', CompanyIntegration::withoutCompany()->findOrFail($row->id)->secret('access_token'), 'a save replaces the old ciphertext');
    }

    public function test_a_key_moved_to_the_previous_keys_still_opens_the_row(): void
    {
        $oldKey = str_repeat('o', 32);
        $row = $this->integration('midtrans');
        $this->seal($row, ['server_key' => 'SB-Mid-server-sentinel-0001'], $oldKey);
        CompanyIntegration::encryptUsing((new Encrypter(str_repeat('n', 32), (string) config('app.cipher')))->previousKeys([$oldKey]));

        try {
            $opened = CompanyIntegration::withoutCompany()->findOrFail($row->id);

            $this->assertSame('SB-Mid-server-sentinel-0001', $opened->secret('server_key'), 'APP_PREVIOUS_KEYS opens what was sealed before the rotation');
            $this->assertSame('filled', CompanyIntegration::stateOf($opened));
        } finally {
            CompanyIntegration::encryptUsing(null);
        }
    }

    public function test_a_company_whose_secrets_the_key_no_longer_opens_sees_empty_fields_and_enters_them_again(): void
    {
        $this->buildGroup();
        $admin = $this->groupUser($this->head);
        $twoFactor = $this->enrollTwoFactor($admin);
        $old = ['access_token' => 'EAAG-old-access-sentinel-0001', 'app_secret' => 'old-app-secret-sentinel', 'webhook_verify_token' => 'old-verify-token-sentinel'];
        $row = $this->integration('whatsapp', ['credentials' => $old, 'external_id' => '109876543210987', 'is_enabled' => true], $this->head);
        $this->seal($row, $old, str_repeat('k', 32));
        $save = function (array $fields) use ($admin, $twoFactor): TestResponse {
            $this->travel(31)->seconds(); // a code is good for one 30-second step, and the enrolment spent the first

            return $this->actingAs($admin)->put(route('admin.company.integrations.update', 'whatsapp'), ['code' => $this->totp($twoFactor['secret']), 'is_enabled' => true, 'phone_number_id' => '109876543210987', ...$fields]);
        };

        $this->actingAs($admin)->get(route('admin.company.integrations.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('integrations.whatsapp.state', 'empty')
            ->where('integrations.whatsapp.is_enabled', true)
            ->where('integrations.whatsapp.secrets', ['access_token' => null, 'app_secret' => null, 'webhook_verify_token' => null])
            ->where('integrations.whatsapp.settings', ['phone_number_id' => '109876543210987']));
        $this->actingAs($admin)->get(route('admin.company.settings.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', false));

        // The old secrets do not count as stored: a card that stays switched on needs them entered again.
        $save([])->assertSessionHasErrors(['access_token', 'app_secret', 'webhook_verify_token']);

        $new = ['access_token' => 'EAAG-new-access-sentinel-'.str_repeat('a', 20), 'app_secret' => 'new-app-secret-sentinel', 'webhook_verify_token' => 'new-verify-token-sentinel-0001'];
        $save($new)->assertSessionHasNoErrors()->assertSessionHas('success', 'Integrasi WhatsApp disimpan.');

        $raw = (string) DB::table('company_integrations')->where('id', $row->id)->value('credentials');
        $this->assertSame($new, json_decode(Crypt::decryptString($raw), true), 'the ciphertext of the old key is replaced');
        $this->actingAs($admin)->get(route('admin.company.integrations.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('integrations.whatsapp.state', 'filled')
            ->where('integrations.whatsapp.secrets', ['access_token' => 'tersimpan (••••aaaa)', 'app_secret' => 'tersimpan (••••inel)', 'webhook_verify_token' => 'tersimpan (••••0001)']));
        $this->actingAs($admin)->get(route('admin.company.settings.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', true));
    }

    public function test_a_companys_integrations_go_with_the_company(): void
    {
        $other = Company::factory()->create();
        $this->integration('midtrans');
        $this->integration('radius');
        $kept = $this->integration('midtrans', [], $other);

        // The purge of a tenant deletes the company rows last; the foreign key takes the integrations with them.
        DB::table('companies')->where('id', $this->company->id)->delete();

        $this->assertSame([$kept->id], CompanyIntegration::withoutCompany()->pluck('id')->all());
    }

    public function test_one_company_has_one_row_per_provider(): void
    {
        $this->integration('mail');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->integration('mail');
    }

    public function test_the_push_test_limiter_allows_three_a_minute_per_user(): void
    {
        $limiter = RateLimiter::limiter('api-push-test');
        $for = function (?User $user) use ($limiter): Limit {
            $request = Request::create('/api/v1/me/push-test', 'POST');
            $request->setUserResolver(fn () => $user);

            return $limiter($request);
        };
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertNotNull($limiter);
        $this->assertSame([3, 60], [$for($first)->maxAttempts, $for($first)->decaySeconds]);
        $this->assertSame($for($first)->key, $for($first)->key);
        $this->assertNotSame($for($first)->key, $for($second)->key, 'a budget per user');
    }

    public function test_the_screen_gets_the_fields_of_its_card_and_never_the_import_only_keys(): void
    {
        $midtrans = $this->integration('midtrans', ['settings' => ['is_production' => true, 'allow_sandbox_in_production' => true, 'base_url' => 'http://127.0.0.1:18911']]);
        $whatsapp = $this->integration('whatsapp', ['external_id' => '1098765432109']);
        $mail = $this->integration('mail', ['settings' => ['host' => 'smtp.isp.test', 'port' => 465, 'scheme' => 'smtps', 'username' => 'billing', 'from_address' => 'billing@isp.test', 'from_name' => 'ISP', 'transport' => 'array']]);

        $this->assertSame(['is_production' => true], $midtrans->publicSettings());
        $this->assertSame(['phone_number_id' => '1098765432109'], $whatsapp->publicSettings());
        $this->assertSame(['host' => 'smtp.isp.test', 'port' => 465, 'scheme' => 'smtps', 'username' => 'billing', 'from_address' => 'billing@isp.test', 'from_name' => 'ISP'], $mail->publicSettings());
        $this->assertSame(
            ['host' => null, 'port' => 3306, 'database' => null, 'username' => null],
            (new CompanyIntegration(['provider' => 'radius']))->publicSettings(),
            'an empty card starts from the usual port',
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function hosts(): array
    {
        return [
            'a public name' => ['smtp.example.com', true],
            'a short name on the LAN' => ['mailserver', true],
            'a compose service name' => ['radius_db', true],
            'a LAN address' => ['192.168.1.10', true],
            'a 10/8 address' => ['10.20.30.40', true],
            'a 172.16/12 address' => ['172.16.5.5', true],
            'a public address' => ['8.8.8.8', true],
            'a public IPv6 address' => ['2001:4860:4860::8888', true],
            'a unique local IPv6 address' => ['fd00::1', true],
            'the cloud metadata address' => ['169.254.169.254', false],
            'any link-local address' => ['169.254.0.7', false],
            'a link-local IPv6 address' => ['fe80::1', false],
            'the unspecified address' => ['0.0.0.0', false],
            'the unspecified IPv6 address' => ['::', false],
            'this network' => ['0.1.2.3', false],
            'multicast' => ['224.0.0.1', false],
            'IPv6 multicast' => ['ff02::1', false],
            'limited broadcast' => ['255.255.255.255', false],
            'metadata behind an IPv4-mapped address' => ['::ffff:169.254.169.254', false],
            'metadata in a decimal address' => ['2852039166', false],
            'an URL' => ['http://smtp.example.com', false],
            'a name with a port' => ['smtp.example.com:25', false],
            'a name with a space' => ['smtp example.com', false],
            'a name ending in a newline' => ["smtp.example.com\n", false],
            'an empty label' => ['smtp..example.com', false],
            'a name with a digit-only last label' => ['mail.123', false],
            'nothing' => ['', false],
        ];
    }

    #[DataProvider('hosts')]
    public function test_the_host_policy(string $host, bool $allowed): void
    {
        $this->assertSame($allowed, CompanyIntegration::isAllowedHost($host), $host);
    }

    public function test_loopback_is_for_local_testing_and_e2e_only(): void
    {
        foreach (['127.0.0.1', '127.5.5.5', '::1', '2130706433', '0x7f.1', '::ffff:127.0.0.1', 'localhost', 'mail.localhost'] as $host) {
            $this->assertTrue(CompanyIntegration::isAllowedHost($host), "{$host} in testing");
        }

        foreach (['production', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            foreach (['127.0.0.1', '127.5.5.5', '::1', '2130706433', '0x7f.1', '::ffff:127.0.0.1', 'localhost', 'mail.localhost', 'LOCALHOST'] as $host) {
                $this->assertFalse(CompanyIntegration::isAllowedHost($host), "{$host} in {$environment}");
            }

            $this->assertTrue(CompanyIntegration::isAllowedHost('192.168.1.10'), 'the ISP LAN stays allowed');
        }
    }
}
