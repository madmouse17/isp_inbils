<?php

namespace Tests\Feature\Ipam;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Ipam\Events\IpAssignmentChanged;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

class IpAssignmentTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
    }

    private function pool(string $cidr, string $usage = 'customer_static'): IpPrefix
    {
        return IpPrefixService::create(['cidr' => $cidr, 'usage' => $usage]);
    }

    private function assign(ServiceSubscription $subscription, IpPrefix $pool, ?string $address = null): TestResponse
    {
        return $this->postJson(route('admin.ipam.subscription-addresses.store', $subscription), [
            'ip_prefix_id' => $pool->public_id,
            'address' => $address,
        ]);
    }

    private function held(ServiceSubscription $subscription, int $family = 4): ?string
    {
        return IpAddress::query()->where('subscription_id', $subscription->id)->where('family', $family)->value('address');
    }

    public function test_auto_assign_gives_the_lowest_free_usable_address(): void
    {
        $pool = $this->pool('10.60.0.0/24');
        $first = $this->makeSubscription($this->company);
        $second = $this->makeSubscription($this->company);

        $this->assign($first, $pool)->assertRedirect();
        $this->assign($second, $pool)->assertRedirect();

        $this->assertSame('10.60.0.1', $this->held($first));
        $this->assertSame('10.60.0.2', $this->held($second));
        $row = IpAddress::query()->where('address', '10.60.0.1')->firstOrFail();
        $this->assertSame('assigned', $row->status);
        $this->assertSame($pool->id, $row->ip_prefix_id);
        $this->assertSame($this->admin->id, $row->assigned_by);
        $this->assertSame('010.060.000.001', $row->address_cmp);
    }

    public function test_auto_assign_skips_the_network_and_broadcast_address_and_reserved_rows(): void
    {
        $pool = $this->pool('10.60.1.0/29');
        IpAssignmentService::reserve($pool, '10.60.1.1', 'gateway');
        IpAssignmentService::reserve($pool, '10.60.1.2');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool)->assertRedirect();

        $this->assertSame('10.60.1.3', $this->held($subscription));
    }

    public function test_auto_assign_never_hands_out_the_broadcast_address_and_a_full_pool_is_422(): void
    {
        $pool = $this->pool('10.60.2.0/30');
        $a = $this->makeSubscription($this->company);
        $b = $this->makeSubscription($this->company);
        $c = $this->makeSubscription($this->company);

        $this->assign($a, $pool)->assertRedirect();
        $this->assign($b, $pool)->assertRedirect();
        $this->assign($c, $pool)->assertStatus(422)->assertJsonValidationErrors('ip_prefix_id');

        $this->assertSame('10.60.2.1', $this->held($a));
        $this->assertSame('10.60.2.2', $this->held($b));
        $this->assertNull($this->held($c));
        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.60.2.3']);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.60.2.0']);
    }

    public function test_a_slash_31_pool_uses_both_addresses(): void
    {
        $pool = $this->pool('10.60.3.0/31');
        $a = $this->makeSubscription($this->company);
        $b = $this->makeSubscription($this->company);
        $c = $this->makeSubscription($this->company);

        $this->assign($a, $pool)->assertRedirect();
        $this->assign($b, $pool)->assertRedirect();
        $this->assign($c, $pool)->assertStatus(422);

        $this->assertSame('10.60.3.0', $this->held($a));
        $this->assertSame('10.60.3.1', $this->held($b));
    }

    public function test_a_slash_32_pool_holds_exactly_one_address(): void
    {
        $pool = $this->pool('10.60.4.9/32');
        $a = $this->makeSubscription($this->company);
        $b = $this->makeSubscription($this->company);

        $this->assign($a, $pool)->assertRedirect();
        $this->assign($b, $pool)->assertStatus(422);

        $this->assertSame('10.60.4.9', $this->held($a));
    }

    public function test_a_typed_address_inside_the_pool_is_assigned(): void
    {
        $pool = $this->pool('10.60.5.0/24');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool, '10.60.5.77')->assertRedirect();

        $this->assertSame('10.60.5.77', $this->held($subscription));
    }

    /** @return array<string, array{0: string}> */
    public static function badTypedAddresses(): array
    {
        return [
            'outside the pool' => ['10.61.0.5'],
            'network address' => ['10.60.6.0'],
            'broadcast address' => ['10.60.6.255'],
            'not an address' => ['10.60.6'],
            'other family' => ['2001:db8::5'],
            'ipv4-mapped ipv6' => ['::ffff:10.60.6.5'],
        ];
    }

    #[DataProvider('badTypedAddresses')]
    public function test_a_bad_typed_address_is_422_and_assigns_nothing(string $address): void
    {
        $pool = $this->pool('10.60.6.0/24');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool, $address)->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_a_typed_duplicate_is_422_naming_the_current_holder(): void
    {
        $pool = $this->pool('10.60.7.0/24');
        $holder = $this->makeSubscription($this->company);
        $other = $this->makeSubscription($this->company);
        $this->assign($holder, $pool, '10.60.7.10')->assertRedirect();

        $response = $this->assign($other, $pool, '10.60.7.10')->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertStringContainsString($holder->code, $response->json('errors.address.0'));
        $this->assertStringContainsString('10.60.7.10', $response->json('errors.address.0'));
        $this->assertNull($this->held($other));
    }

    public function test_a_typed_address_held_by_an_asset_or_a_reservation_is_refused_by_name(): void
    {
        $pool = $this->pool('10.60.8.0/24');
        $router = $this->makeAsset($this->company, 'router', null, ['management_ip' => '10.60.8.20']);
        IpAssignmentService::syncAssetAddresses($router);
        IpAssignmentService::reserve($pool, '10.60.8.30', 'gateway');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool, '10.60.8.20')->assertStatus(422)
            ->assertJsonPath('errors.address.0', "10.60.8.20 sudah dipakai aset {$router->code}.");
        $this->assign($subscription, $pool, '10.60.8.30')->assertStatus(422)
            ->assertJsonPath('errors.address.0', '10.60.8.30 sudah dicadangkan (gateway).');
    }

    public function test_the_same_address_can_be_held_in_two_companies(): void
    {
        $pool = $this->pool('10.60.9.0/24');
        $mine = $this->makeSubscription($this->company);
        $this->assign($mine, $pool, '10.60.9.5')->assertRedirect();

        $other = $this->otherCompany();
        $theirs = $this->makeSubscription($other);
        $this->inCompany($other, function () use ($theirs) {
            $theirPool = IpPrefixService::create(['cidr' => '10.60.9.0/24', 'usage' => 'customer_static']);
            IpAssignmentService::assignToSubscription($theirs, $theirPool, '10.60.9.5');
        });

        $this->assertSame(2, IpAddress::withoutCompany()->where('address', '10.60.9.5')->count());
    }

    public function test_a_second_ipv4_address_for_one_subscription_is_422(): void
    {
        $pool = $this->pool('10.60.10.0/24');
        $other = $this->pool('10.60.11.0/24');
        $subscription = $this->makeSubscription($this->company);
        $this->assign($subscription, $pool)->assertRedirect();

        $this->assign($subscription, $pool)->assertStatus(422)->assertJsonValidationErrors('ip_prefix_id');
        $this->assign($subscription, $other, '10.60.11.9')->assertStatus(422)->assertJsonValidationErrors('ip_prefix_id');

        $this->assertSame(1, IpAddress::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_a_subscription_can_hold_one_ipv4_and_one_ipv6_address(): void
    {
        $v4 = $this->pool('10.60.12.0/24');
        $v6 = $this->pool('2001:db8:60::/48');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $v4)->assertRedirect();
        $this->assign($subscription, $v6, '2001:DB8:60:0::10')->assertRedirect();

        $this->assertSame('10.60.12.1', $this->held($subscription, 4));
        $this->assertSame('2001:db8:60::10', $this->held($subscription, 6));
        $this->assertSame('10.60.12.1', IpAssignmentService::staticAddressFor($subscription), 'provisioning reads the IPv4 only');
    }

    public function test_an_ipv6_pool_needs_a_typed_address(): void
    {
        $pool = $this->pool('2001:db8:61::/48');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool)->assertStatus(422)->assertJsonValidationErrors('address');
    }

    public function test_a_pool_wider_than_a_slash_16_refuses_automatic_allocation_but_takes_a_typed_address(): void
    {
        $pool = $this->pool('10.64.0.0/12');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $pool)->assertStatus(422)->assertJsonValidationErrors('address');
        $this->assign($subscription, $pool, '10.64.0.9')->assertRedirect();

        $this->assertSame('10.64.0.9', $this->held($subscription));
    }

    public function test_only_a_static_customer_pool_can_hand_out_a_subscription_address(): void
    {
        $subscription = $this->makeSubscription($this->company);

        foreach (['container' => '10.70.0.0/24', 'customer_dynamic' => '10.70.1.0/24', 'infrastructure' => '10.70.2.0/24'] as $usage => $cidr) {
            $this->assign($subscription, $this->pool($cidr, $usage))->assertStatus(422)->assertJsonValidationErrors('ip_prefix_id');
        }

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_a_typed_address_inside_a_nested_prefix_of_another_usage_is_refused(): void
    {
        $outer = $this->pool('10.71.0.0/22');
        $this->pool('10.71.1.0/24', 'infrastructure');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $outer, '10.71.1.9')->assertStatus(422)->assertJsonValidationErrors('address');
        $this->assign($subscription, $outer, '10.71.2.9')->assertRedirect();
    }

    public function test_automatic_allocation_leaves_space_carved_out_for_a_nested_prefix_alone(): void
    {
        $outer = $this->pool('10.72.0.0/29');
        $this->pool('10.72.0.0/30', 'infrastructure');
        $subscription = $this->makeSubscription($this->company);

        $this->assign($subscription, $outer)->assertRedirect();

        $this->assertSame('10.72.0.4', $this->held($subscription));
    }

    public function test_a_terminated_subscription_cannot_be_given_an_address(): void
    {
        $pool = $this->pool('10.60.13.0/24');
        $terminated = $this->makeSubscription($this->company, 'terminated');

        $this->assign($terminated, $pool)->assertStatus(422)->assertJsonValidationErrors('subscription');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_routes_refuse_another_companys_subscription_and_pool(): void
    {
        $pool = $this->pool('10.60.14.0/24');
        $mine = $this->makeSubscription($this->company);
        $other = $this->otherCompany();
        $theirs = $this->makeSubscription($other);
        $foreignPool = $this->inCompany($other, fn () => IpPrefix::factory()->cidr('10.60.99.0/24')->create(['company_id' => $other->id]));

        $this->assign($theirs, $pool)->assertNotFound();
        $this->assign($mine, $foreignPool)->assertStatus(422)->assertJsonValidationErrors('ip_prefix_id');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_releasing_an_address_deletes_the_row_and_returns_it_to_the_pool(): void
    {
        $pool = $this->pool('10.60.15.0/24');
        $first = $this->makeSubscription($this->company);
        $second = $this->makeSubscription($this->company);
        $this->assign($first, $pool)->assertRedirect();
        $row = IpAddress::query()->where('subscription_id', $first->id)->firstOrFail();

        $this->deleteJson(route('admin.ipam.addresses.destroy', $row))->assertRedirect();

        $this->assertDatabaseMissing('ip_addresses', ['id' => $row->id]);
        $this->assign($second, $pool)->assertRedirect();
        $this->assertSame('10.60.15.1', $this->held($second));
    }

    public function test_assign_and_release_are_audited_with_subscription_and_address(): void
    {
        $pool = $this->pool('10.60.16.0/24');
        $subscription = $this->makeSubscription($this->company);
        $this->assign($subscription, $pool)->assertRedirect();
        $row = IpAddress::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->deleteJson(route('admin.ipam.addresses.destroy', $row))->assertRedirect();

        $assigned = Activity::query()->where('log_name', 'ipam')->where('description', 'address_assigned')->firstOrFail();
        $released = Activity::query()->where('log_name', 'ipam')->where('description', 'address_released')->firstOrFail();

        $this->assertSame($subscription->id, $assigned->properties['subscription_id']);
        $this->assertSame('10.60.16.1', $assigned->properties['address']);
        $this->assertSame('lowest_free', $assigned->properties['mode']);
        $this->assertSame($this->admin->id, $assigned->causer_id);
        $this->assertSame($subscription->id, $released->properties['subscription_id']);
        $this->assertSame($this->company->id, (int) $released->company_id);
        $this->assertSame(['created', 'deleted'], Activity::query()->where('log_name', 'ip_address')->orderBy('id')->pluck('description')->all());
    }

    public function test_an_asset_held_address_cannot_be_released_from_the_register(): void
    {
        $router = $this->makeAsset($this->company, 'router', null, ['management_ip' => '10.60.17.1']);
        IpAssignmentService::syncAssetAddresses($router);
        $row = IpAddress::query()->where('address', '10.60.17.1')->firstOrFail();

        $this->deleteJson(route('admin.ipam.addresses.destroy', $row))->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertDatabaseHas('ip_addresses', ['id' => $row->id]);
    }

    public function test_assign_and_release_dispatch_the_assignment_changed_event(): void
    {
        $pool = $this->pool('10.60.18.0/24');
        $subscription = $this->makeSubscription($this->company);
        Event::fake([IpAssignmentChanged::class]);

        $this->assign($subscription, $pool)->assertRedirect();
        Event::assertDispatchedTimes(IpAssignmentChanged::class, 1);

        $row = IpAddress::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->deleteJson(route('admin.ipam.addresses.destroy', $row))->assertRedirect();

        Event::assertDispatchedTimes(IpAssignmentChanged::class, 2);
        Event::assertDispatched(IpAssignmentChanged::class, fn (IpAssignmentChanged $event): bool => $event->subscriptionId === $subscription->id
            && $event->companyId === $this->company->id);
    }

    public function test_the_event_is_not_dispatched_for_a_reservation_or_a_rejected_assignment(): void
    {
        $pool = $this->pool('10.60.19.0/30');
        $subscription = $this->makeSubscription($this->company);
        Event::fake([IpAssignmentChanged::class]);

        IpAssignmentService::reserve($pool, '10.60.19.1');
        IpAssignmentService::reserve($pool, '10.60.19.2');
        $this->assign($subscription, $pool)->assertStatus(422);

        Event::assertNotDispatched(IpAssignmentChanged::class);
    }

    public function test_the_event_fires_after_the_surrounding_transaction_commits_and_never_on_rollback(): void
    {
        $pool = $this->pool('10.60.20.0/24');
        $subscription = $this->makeSubscription($this->company);
        $other = $this->makeSubscription($this->company);
        $levels = [];
        Event::listen(IpAssignmentChanged::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $baseline = DB::transactionLevel();

        DB::transaction(function () use ($pool, $subscription, &$levels): void {
            IpAssignmentService::assignToSubscription($subscription, $pool);
            $this->assertSame([], $levels, 'nothing is dispatched while the outer transaction is open');
        });

        $this->assertSame([$baseline], $levels);

        try {
            DB::transaction(function () use ($pool, $other): void {
                IpAssignmentService::assignToSubscription($other, $pool);

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(1, $levels, 'a rolled-back assignment dispatches nothing');
        $this->assertNull($this->held($other));
    }

    public function test_static_address_for_is_dotted_ipv4_or_null(): void
    {
        $pool = $this->pool('10.60.21.0/24');
        $dynamic = $this->makeSubscription($this->company);
        $static = $this->makeSubscription($this->company);

        $this->assertNull(IpAssignmentService::staticAddressFor($dynamic));

        $this->assign($static, $pool, '10.60.21.40')->assertRedirect();
        $this->assertSame('10.60.21.40', IpAssignmentService::staticAddressFor($static));
        $this->assertNull(IpAssignmentService::staticAddressFor($dynamic));

        $this->assertSame(1, IpAssignmentService::forSubscription($static)->count());
        $this->assertSame('10.60.21.0/24', IpAssignmentService::forSubscription($static)->first()->prefix->cidr);
    }

    public function test_static_address_for_needs_no_ambient_company(): void
    {
        $pool = $this->pool('10.60.22.0/24');
        $subscription = $this->makeSubscription($this->company);
        $this->assign($subscription, $pool, '10.60.22.9')->assertRedirect();

        $this->app['auth']->logout();

        $this->assertSame('10.60.22.9', IpAssignmentService::staticAddressFor($subscription));
    }

    public function test_release_for_subscription_frees_every_family_and_dispatches_per_address(): void
    {
        $v4 = $this->pool('10.60.23.0/24');
        $v6 = $this->pool('2001:db8:62::/48');
        $subscription = $this->makeSubscription($this->company);
        $this->assign($subscription, $v4);
        $this->assign($subscription, $v6, '2001:db8:62::1');
        Event::fake([IpAssignmentChanged::class]);

        $released = IpAssignmentService::releaseForSubscription($subscription);

        $this->assertSame(2, $released);
        $this->assertDatabaseCount('ip_addresses', 0);
        Event::assertDispatchedTimes(IpAssignmentChanged::class, 2);
    }

    public function test_a_reservation_holds_an_address_back_and_is_counted_as_reserved(): void
    {
        $pool = $this->pool('10.60.24.0/24');

        $this->postJson(route('admin.ipam.reservations.store', $pool), ['address' => '10.60.24.1', 'note' => 'gateway'])->assertRedirect();

        $row = IpAddress::query()->where('address', '10.60.24.1')->firstOrFail();
        $this->assertSame('reserved', $row->status);
        $this->assertNull($row->subscription_id);
        $this->assertNull($row->network_asset_id);
        $this->assertSame('gateway', $row->note);
        $this->assertSame($pool->id, $row->ip_prefix_id);
    }

    public function test_a_reservation_outside_the_prefix_on_a_taken_address_or_on_the_network_address_is_422(): void
    {
        $pool = $this->pool('10.60.25.0/24');
        $this->postJson(route('admin.ipam.reservations.store', $pool), ['address' => '10.60.25.1'])->assertRedirect();

        foreach (['10.99.0.1' => 'outside', '10.60.25.1' => 'taken', '10.60.25.0' => 'network', '10.60.25.255' => 'broadcast', 'x' => 'invalid'] as $address => $why) {
            $this->postJson(route('admin.ipam.reservations.store', $pool), ['address' => (string) $address])
                ->assertStatus(422)->assertJsonValidationErrors('address');
        }

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_a_reservation_can_be_released_with_the_register_permission(): void
    {
        $pool = $this->pool('10.60.26.0/24');
        $reservation = IpAssignmentService::reserve($pool, '10.60.26.5');

        $this->deleteJson(route('admin.ipam.addresses.destroy', $reservation))->assertRedirect();

        $this->assertDatabaseMissing('ip_addresses', ['id' => $reservation->id]);
    }
}
