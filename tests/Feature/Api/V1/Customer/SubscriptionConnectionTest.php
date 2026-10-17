<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\InteractsWithRadius;

/**
 * GET customer/subscriptions/{id}/connection: the one line of the Home hero ("Online sejak 08.12"), read live
 * from RADIUS. The app gets a status and two times, never an address or a session id, and null while there is
 * nothing true to say.
 */
class SubscriptionConnectionTest extends TestCase
{
    use InteractsWithMobileApi;
    use InteractsWithRadius;
    use RefreshDatabase;

    private const SESSION_ID = 'SECRET-session-0001';

    private User $user;

    private Customer $customer;

    private string $token;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 14, 8, 30, 0, 'UTC'));
        [$this->user, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->user, 'customer');
        $this->subscription = $this->subscriptionWithAccount($this->customer, 'budi@acme');
        $this->fakeRadius();
    }

    public function test_an_open_session_is_online_since_it_started(): void
    {
        $this->seedRadiusSession(['acctsessionid' => self::SESSION_ID, 'acctstarttime' => '2026-10-14 08:12:00', 'acctupdatetime' => '2026-10-14 08:29:00']);

        $response = $this->connection($this->subscription)->assertOk();

        $this->assertSame(['data' => ['status' => 'online', 'since' => '2026-10-14T08:12:00+00:00', 'last_seen_at' => null]], $response->json());
        $this->assertNoRadiusDetail($response);
    }

    public function test_without_an_open_session_it_is_offline_with_the_time_the_last_one_ended(): void
    {
        $this->seedRadiusSession(['acctsessionid' => self::SESSION_ID, 'acctstarttime' => '2026-10-13 20:00:00', 'acctstoptime' => '2026-10-14 06:40:00']);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-14 06:50:00', 'acctstoptime' => '2026-10-14 07:55:00']);

        $response = $this->connection($this->subscription)->assertOk();

        $this->assertSame(['data' => ['status' => 'offline', 'since' => null, 'last_seen_at' => '2026-10-14T07:55:00+00:00']], $response->json());
        $this->assertNoRadiusDetail($response);
    }

    public function test_a_session_that_stopped_reporting_is_offline_not_online(): void
    {
        $this->seedRadiusSession(['acctstarttime' => '2026-10-14 05:00:00', 'acctupdatetime' => '2026-10-14 08:10:00']);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-13 20:00:00', 'acctstoptime' => '2026-10-14 04:30:00']);

        $this->assertSame(
            ['status' => 'offline', 'since' => null, 'last_seen_at' => '2026-10-14T04:30:00+00:00'],
            $this->connection($this->subscription)->assertOk()->json('data'),
        );
    }

    public function test_a_subscriber_who_never_connected_is_offline_without_a_time(): void
    {
        $this->seedRadiusSession(['username' => 'ani@acme']);

        $this->assertSame(
            ['status' => 'offline', 'since' => null, 'last_seen_at' => null],
            $this->connection($this->subscription)->assertOk()->json('data'),
        );
    }

    public function test_while_radius_is_switched_off_the_answer_is_null_and_radius_is_not_asked(): void
    {
        $this->seedRadiusSession();
        config(['services.radius.enabled' => false]);
        $this->radiusConnection()->enableQueryLog();

        $this->connection($this->subscription)->assertOk()->assertExactJson(['data' => null]);

        $this->assertSame([], $this->radiusConnection()->getQueryLog());
    }

    public function test_a_radius_database_that_cannot_be_reached_answers_null_not_a_500(): void
    {
        config(['database.connections.radius' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'fake_radius_db',
            'username' => 'fake_radius_user',
            'password' => 'fake-db-password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        $response = $this->connection($this->subscription)->assertOk()->assertExactJson(['data' => null]);

        foreach (['127.0.0.1', 'fake_radius_user', 'fake_radius_db', 'fake-db-password'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent());
        }
    }

    public function test_a_subscription_without_a_pppoe_username_answers_null_and_radius_is_not_asked(): void
    {
        $this->seedRadiusSession(['username' => 'hotspot@acme']);
        $this->seedRadiusSession(['username' => 'alone@acme']);
        $withoutAccount = $this->subscriptionWithAccount($this->customer, null);
        $hotspot = $this->subscriptionWithAccount($this->customer, 'hotspot@acme', 'hotspot');
        $this->radiusConnection()->enableQueryLog();

        foreach ([$withoutAccount, $hotspot] as $subscription) {
            $this->connection($subscription)->assertOk()->assertExactJson(['data' => null]);
        }

        $this->assertSame([], $this->radiusConnection()->getQueryLog());
    }

    public function test_another_customers_or_companys_subscription_is_a_404_and_radius_is_not_asked(): void
    {
        [, $peer] = $this->customerLogin($this->user->company);
        [, $stranger] = $this->customerLogin();
        $this->seedRadiusSession();
        $this->seedRadiusSession(['username' => 'peer@acme']);
        $this->seedRadiusSession(['username' => 'stranger@other']);
        $this->connection($this->subscription)->assertOk()->assertJsonPath('data.status', 'online');
        $this->radiusConnection()->enableQueryLog();

        foreach ([$this->subscriptionWithAccount($peer, 'peer@acme'), $this->subscriptionWithAccount($stranger, 'stranger@other')] as $foreign) {
            $this->connection($foreign)->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        $this->api('GET', 'customer/subscriptions/9999999/connection', token: $this->token)->assertNotFound();

        $this->assertSame([], $this->radiusConnection()->getQueryLog());
    }

    private function connection(ServiceSubscription $subscription): TestResponse
    {
        return $this->api('GET', "customer/subscriptions/{$subscription->public_id}/connection", token: $this->token);
    }

    /** NAS and framed addresses and the session id are what an app must never see, in a key or as a value. */
    private function assertNoRadiusDetail(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        foreach (['nas_ip', 'nasipaddress', 'framed_ip', 'framedipaddress', 'session_id', 'acctsessionid', 'username', 'budi@acme', '192.0.2.10', '198.51.100.7', self::SESSION_ID] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "{$leak} leaked into the response");
        }
    }

    private function subscriptionWithAccount(Customer $customer, ?string $username, string $serviceType = 'pppoe'): ServiceSubscription
    {
        $companyId = $customer->company_id;
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $companyId])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'label' => uniqid('Rumah ')])->id,
            'status' => 'active',
        ]);

        if ($username !== null) {
            NetworkAccount::factory()->create([
                'company_id' => $companyId,
                'subscription_id' => $subscription->id,
                'service_type' => $serviceType,
                'username' => $username,
            ]);
        }

        return $subscription;
    }
}
