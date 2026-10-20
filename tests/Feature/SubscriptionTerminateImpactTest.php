<?php

namespace Tests\Feature;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\OdnService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\InteractsWithRadius;

/**
 * The "Hentikan" confirmation lists what terminating does (F7-U24): only what applies to the subscription, and an
 * address, account name or port only to a user who may view that register.
 */
class SubscriptionTerminateImpactTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsNetworkFixtures;
    use BuildsOdnTopology;
    use InteractsWithRadius;
    use RefreshDatabase;

    private const BILLING = 'Tagihan bulanan dihitung sampai hari berhenti, setelah itu tidak dibuat lagi';

    /** @var array<string, mixed> */
    private array $net;

    /** @var list<OdnPort> */
    private array $outputs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(withSeededRoles: true);
        $this->fakeRadius();
        $this->net = $this->topology($this->company);
        [, $this->outputs] = $this->splitter($this->net['odp'], 8);
    }

    /** An active subscription with a static address, a drop port and a network account, in that order (the account last: it would queue a RADIUS apply). */
    private function holdingEverything(): ServiceSubscription
    {
        $pool = IpPrefixService::create(['cidr' => '10.120.0.0/24', 'usage' => 'customer_static', 'location_id' => $this->net['pop']->id]);
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        IpAssignmentService::assignToSubscription($subscription, $pool, '10.120.0.9');
        OdnService::assignDrop($this->outputs[2], $subscription);
        $this->networkAccountFor($subscription);

        return $subscription;
    }

    /** @return ?list<string> */
    private function impactOf(ServiceSubscription $subscription): ?array
    {
        return $this->get(route('admin.subscriptions.show', $subscription))->assertOk()->inertiaProps()['terminateImpact'];
    }

    private function userMayOnly(string ...$permissions): void
    {
        Role::firstOrCreate(['name' => 'impact-reader', 'guard_name' => 'web'])->syncPermissions($permissions);
        $this->actingAs($this->makeUser($this->company, 'impact-reader'));
    }

    public function test_impact_lists_only_what_applies(): void
    {
        $realm = $this->realmOf($this->company);
        $full = $this->holdingEverything();

        $this->assertSame([
            "Akun PPPoE alice@{$realm} dicabut dari RADIUS dan sesi yang tersambung diputus",
            'Alamat IP statis 10.120.0.9 kembali ke pool IPAM',
            'Port drop 3 di ODP-A11 dibebaskan',
            self::BILLING,
        ], $this->impactOf($full));

        $this->assertSame([self::BILLING], $this->impactOf($this->makeSubscription($this->company, 'active')), 'nothing attached');
        $this->assertSame([self::BILLING], $this->impactOf($this->makeSubscription($this->company, 'suspended')), 'billing resumes on reactivation');
        $this->assertSame([], $this->impactOf($this->makeSubscription($this->company, 'pending')), 'not billed yet');

        $ended = $this->makeSubscription($this->company, 'terminated');
        $this->networkAccountFor($ended, ['username' => "ended@{$realm}"]);
        $this->assertSame([], $this->impactOf($ended), 'everything was given back when it ended');
    }

    public function test_the_radius_line_waits_for_a_company_that_has_radius(): void
    {
        $full = $this->holdingEverything();
        $this->disableRadius();

        $this->assertSame(['Alamat IP statis 10.120.0.9 kembali ke pool IPAM', 'Port drop 3 di ODP-A11 dibebaskan', self::BILLING], $this->impactOf($full));
    }

    public function test_a_hotspot_account_is_named_as_one(): void
    {
        $subscription = $this->makeSubscription($this->company, 'active');
        $this->networkAccountFor($subscription, ['service_type' => 'hotspot']);

        $this->assertStringStartsWith('Akun Hotspot alice@', $this->impactOf($subscription)[0]);
    }

    public function test_impact_absent_without_terminate_permission(): void
    {
        $full = $this->holdingEverything();
        $this->userMayOnly('customer.subscription.view', 'customer.subscription.manage', 'ipam.view', 'odn.view', 'network_asset.account.view');

        $this->get(route('admin.subscriptions.show', $full))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('terminateImpact', null));
    }

    public function test_impact_names_only_what_the_user_may_view(): void
    {
        $realm = $this->realmOf($this->company);
        $full = $this->holdingEverything();
        $this->userMayOnly('customer.subscription.view', 'customer.subscription.terminate');

        $response = $this->get(route('admin.subscriptions.show', $full))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('terminateImpact', [
                'Akun PPPoE dicabut dari RADIUS dan sesi yang tersambung diputus',
                'Alamat IP statis kembali ke pool IPAM',
                'Port drop dibebaskan',
                self::BILLING,
            ])
            ->where('ipAddresses', [])
            ->where('dropPort', null));
        $encoded = json_encode($response->inertiaProps());
        foreach (['10.120.0.9', "alice@{$realm}", 'ODP-A11'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }

        $this->userMayOnly('customer.subscription.view', 'customer.subscription.terminate', 'ipam.view');
        $impact = $this->impactOf($full);
        $this->assertContains('Alamat IP statis 10.120.0.9 kembali ke pool IPAM', $impact);
        $this->assertContains('Port drop dibebaskan', $impact);
    }
}
