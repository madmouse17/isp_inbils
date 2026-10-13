<?php

namespace Tests\Feature\Ipam;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Q14: admin and manager hold all three IPAM permissions; staff and technician only view; the customer
 * persona holds none. Denied requests are 403 and change nothing.
 */
class IpamPermissionTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const ENDPOINTS = [
        'view' => ['prefixes.index', 'prefixes.show', 'vlans.index'],
        'manage' => [
            'prefixes.store', 'prefixes.update', 'prefixes.destroy', 'reservations.store',
            'vlans.store', 'vlans.update', 'vlans.destroy', 'addresses.destroy.reservation',
        ],
        'assign' => ['subscription-addresses.store', 'addresses.destroy.subscription'],
    ];

    private const ALLOWED = [
        'view' => ['admin', 'manager', 'staff', 'technician'],
        'manage' => ['admin', 'manager'],
        'assign' => ['admin', 'manager'],
    ];

    private IpPrefix $pool;

    private IpPrefix $spare;

    private Vlan $vlan;

    private ServiceSubscription $subscription;

    private IpAddress $reservation;

    private IpAddress $held;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();

        $this->pool = IpPrefixService::create(['cidr' => '10.200.0.0/24', 'usage' => 'customer_static']);
        $this->spare = IpPrefixService::create(['cidr' => '10.201.0.0/24', 'usage' => 'customer_static']);
        $this->vlan = Vlan::factory()->create(['vid' => 77]);
        $this->subscription = $this->makeSubscription($this->company);
        $this->reservation = IpAssignmentService::reserve($this->pool, '10.200.0.2');
        $this->held = IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $this->pool, '10.200.0.3');
    }

    private function perform(string $endpoint): TestResponse
    {
        return match ($endpoint) {
            'prefixes.index' => $this->get(route('admin.ipam.prefixes.index')),
            'prefixes.show' => $this->get(route('admin.ipam.prefixes.show', $this->pool)),
            'vlans.index' => $this->get(route('admin.ipam.vlans.index')),
            'prefixes.store' => $this->postJson(route('admin.ipam.prefixes.store'), ['cidr' => '10.250.0.0/24', 'usage' => 'container']),
            'prefixes.update' => $this->putJson(route('admin.ipam.prefixes.update', $this->pool), ['usage' => 'infrastructure']),
            'prefixes.destroy' => $this->deleteJson(route('admin.ipam.prefixes.destroy', $this->spare)),
            'reservations.store' => $this->postJson(route('admin.ipam.reservations.store', $this->pool), ['address' => '10.200.0.77']),
            'vlans.store' => $this->postJson(route('admin.ipam.vlans.store'), ['vid' => 78, 'name' => 'New']),
            'vlans.update' => $this->putJson(route('admin.ipam.vlans.update', $this->vlan), ['vid' => 77, 'name' => 'Renamed']),
            'vlans.destroy' => $this->deleteJson(route('admin.ipam.vlans.destroy', $this->vlan)),
            'addresses.destroy.reservation' => $this->deleteJson(route('admin.ipam.addresses.destroy', $this->reservation)),
            'subscription-addresses.store' => $this->postJson(route('admin.ipam.subscription-addresses.store', $this->subscription), ['ip_prefix_id' => $this->pool->id]),
            'addresses.destroy.subscription' => $this->deleteJson(route('admin.ipam.addresses.destroy', $this->held)),
        };
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function matrix(): array
    {
        $cases = [];

        foreach (self::ENDPOINTS as $capability => $endpoints) {
            foreach (['admin', 'manager', 'staff', 'technician', 'customer'] as $role) {
                foreach ($endpoints as $endpoint) {
                    $cases["{$role} on {$endpoint}"] = [$role, $endpoint, in_array($role, self::ALLOWED[$capability], true)];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_each_role_gets_exactly_the_access_the_matrix_gives_it(string $role, string $endpoint, bool $allowed): void
    {
        $this->actingAs($this->makeUser($this->company, $role));

        $response = $this->perform($endpoint);

        if ($allowed) {
            $this->assertContains($response->status(), [200, 302], "{$role} should reach {$endpoint}, got {$response->status()}");

            return;
        }

        $response->assertForbidden();
        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('ip_prefixes', 2);
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertSame('customer_static', $this->pool->fresh()->usage);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->app['auth']->logout();

        $this->get(route('admin.ipam.prefixes.index'))->assertRedirect(route('login'));
        $this->postJson(route('admin.ipam.prefixes.store'), [])->assertUnauthorized();
    }

    public function test_the_page_flags_follow_the_role(): void
    {
        $this->actingAs($this->makeUser($this->company, 'technician'));

        $this->get(route('admin.ipam.prefixes.index'))->assertInertia(fn ($page) => $page->where('can.manage', false));
        $this->get(route('admin.ipam.prefixes.show', $this->pool))->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.assign', false));
        $this->get(route('admin.ipam.vlans.index'))->assertInertia(fn ($page) => $page->where('can.manage', false));
    }
}
