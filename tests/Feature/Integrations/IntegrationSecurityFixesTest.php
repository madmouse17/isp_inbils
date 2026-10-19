<?php

namespace Tests\Feature\Integrations;

use App\Jobs\TestCompanyIntegration;
use App\Models\Core\CompanyIntegration;
use App\Notifications\Contracts\SentByPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * The fixes of the tenant-integrations security review that no single provider test owns: a mail that carries a
 * credential never leaves from a company's own SMTP server (the behaviour is in CompanyMailChannelTest), and the host
 * policy holds at connect time for the platform's own services. The runtime paths (the mail transport, the RADIUS
 * connection) are in CompanyMailChannelTest and RadiusPerCompanyTest; here is the policy itself and "Uji koneksi".
 *
 * The platform's database host is `mysql`: the suite does not start without it (TestCase::guardPreBootstrapEnvironment),
 * so the address it resolves to stands in for any service of the platform.
 */
class IntegrationSecurityFixesTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private const REFUSED = 'Host %s tidak diizinkan (alamat lokal atau metadata).';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    private function hostRow(string $host, string $provider = 'mail'): CompanyIntegration
    {
        return (new CompanyIntegration())->forceFill(['company_id' => $this->head->id, 'provider' => $provider, 'settings' => ['host' => $host]]);
    }

    private function databaseAddress(): string
    {
        $address = gethostbyname('mysql');

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->markTestSkipped('The test database host does not resolve here.');
        }

        return $address;
    }

    private function production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    public function test_every_notification_that_builds_a_credential_link_is_sent_by_the_platform(): void
    {
        $directories = array_merge([app_path('Notifications')], glob(base_path('Modules/*/app/Notifications')) ?: []);
        $credentialLink = '/createToken\(|temporarySignedRoute|ResetPassword|VerifyEmail/';
        $checked = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories) as $file) {
            $source = $file->getContents();

            if (preg_match($credentialLink, $source) !== 1 || preg_match('/^namespace (.+);/m', $source, $namespace) !== 1 || preg_match('/^(?:abstract |final )?class (\w+)/m', $source, $class) !== 1) {
                continue;
            }

            $name = $namespace[1].'\\'.$class[1];

            if (is_subclass_of($name, Notification::class)) {
                $checked[] = $name;
                $this->assertTrue(is_subclass_of($name, SentByPlatform::class), "{$name} builds a credential link: it must implement SentByPlatform, or a company's SMTP server reads the link.");
            }
        }

        foreach (['App\Notifications\CustomerAccountCreated', 'App\Notifications\Platform\TenantAdminInvitation'] as $known) {
            $this->assertContains($known, $checked, 'the scan still finds the notifications that mint a reset token');
        }
    }

    public function test_a_row_without_a_host_is_left_to_the_providers_own_check(): void
    {
        $this->production();

        $this->assertNull($this->hostRow('')->hostRefusal());
        $this->assertNull((new CompanyIntegration(['provider' => 'mail']))->hostRefusal());
    }

    public function test_an_ordinary_lan_or_public_host_is_allowed_outside_the_local_environments(): void
    {
        $this->production();

        foreach (['192.168.1.10', '10.20.30.40', '8.8.8.8', '2001:4860:4860::8888'] as $host) {
            $this->assertNull($this->hostRow($host)->hostRefusal(), $host);
        }
    }

    public function test_the_platforms_own_database_is_refused_by_address_and_by_name(): void
    {
        $address = $this->databaseAddress();
        $this->production();
        config(['database.connections.mysql.host' => 'mysql']);

        foreach ([$address, "::ffff:{$address}", (string) ip2long($address), 'mysql', 'MySQL'] as $host) {
            $this->assertSame(sprintf(self::REFUSED, $host), $this->hostRow($host)->hostRefusal(), $host);
        }
    }

    public function test_a_name_that_resolves_to_a_service_of_the_platform_is_refused(): void
    {
        $address = $this->databaseAddress();
        $this->production();
        // The platform names its Redis by address, the company names that machine: only the lookup tells they are one.
        config(['database.connections.mysql.host' => 'db.platform.test', 'database.redis.default.host' => $address]);

        $this->assertSame(sprintf(self::REFUSED, 'mysql'), $this->hostRow('mysql', 'radius')->hostRefusal());
    }

    public function test_the_redis_and_the_mail_host_of_the_platform_are_refused_like_its_database(): void
    {
        $this->production();
        config(['database.redis.default.host' => 'redis.platform.test', 'mail.mailers.smtp.host' => '10.9.8.9']);

        foreach (['redis.platform.test', '10.9.8.9'] as $host) {
            $this->assertSame(sprintf(self::REFUSED, $host), $this->hostRow($host)->hostRefusal(), $host);
        }

        $this->assertNull($this->hostRow('10.9.8.10')->hostRefusal(), 'the neighbour is a LAN host');
    }

    public function test_the_platforms_own_hosts_are_a_laboratory_in_the_local_environments(): void
    {
        $address = $this->databaseAddress();
        config(['database.redis.default.host' => '10.9.8.8', 'mail.mailers.smtp.host' => '10.9.8.9']);

        foreach (['local', 'testing', 'e2e'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            foreach ([$address, 'mysql', '10.9.8.8', '10.9.8.9'] as $host) {
                $this->assertNull($this->hostRow($host)->hostRefusal(), "{$host} in {$environment}");
            }
        }
    }

    public function test_the_connection_test_refuses_a_platform_host_as_the_runtime_does(): void
    {
        config(['mail.mailers.smtp.host' => '10.9.8.9', 'database.redis.default.host' => '10.9.8.8']);
        $admin = $this->groupUser($this->head);
        $mail = CompanyIntegration::query()->create(['company_id' => $this->head->id, 'provider' => 'mail', 'settings' => ['host' => '10.9.8.9', 'from_address' => 'a@isp.test'], 'is_enabled' => true]);
        $radius = CompanyIntegration::query()->create(['company_id' => $this->head->id, 'provider' => 'radius', 'settings' => ['host' => '10.9.8.8', 'database' => 'radius', 'username' => 'inbils'], 'is_enabled' => true]);
        $this->production();

        TestCompanyIntegration::dispatchSync($mail->id, $admin->id);
        TestCompanyIntegration::dispatchSync($radius->id, $admin->id);

        $this->assertSame(sprintf(self::REFUSED, '10.9.8.9'), $mail->refresh()->last_error);
        $this->assertSame(sprintf(self::REFUSED, '10.9.8.8'), $radius->refresh()->last_error);
        $this->assertNull($mail->verified_at);
        $this->assertNull($radius->verified_at);
    }
}
