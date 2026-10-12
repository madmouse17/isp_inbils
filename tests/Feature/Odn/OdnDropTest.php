<?php

namespace Tests\Feature\Odn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\OdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

class OdnDropTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $net;

    /** @var list<OdnPort> outputs of a 1:8 splitter in the ODP */
    private array $outputs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
        $this->net = $this->topology($this->company);
        [, $this->outputs] = $this->splitter($this->net['odp'], 8);
    }

    private function drop(OdnPort $port, int $subscriptionId): TestResponse
    {
        return $this->postJson(route('admin.odn.drops.store', $port), ['subscription_id' => $subscriptionId]);
    }

    private function portOf(int $subscriptionId): ?int
    {
        return OdnPort::query()->where('subscription_id', $subscriptionId)->value('id');
    }

    public function test_a_free_odp_output_port_takes_a_subscription_drop(): void
    {
        $subscription = $this->makeSubscription($this->company);

        $this->drop($this->outputs[2], $subscription->id)->assertRedirect();

        $this->assertSame($subscription->id, $this->outputs[2]->fresh()->subscription_id);
        $this->assertSame($this->outputs[2]->id, $this->portOf($subscription->id));
    }

    /** @return array<string, array{0: string}> */
    public static function allowedStatuses(): array
    {
        return ['pending' => ['pending'], 'active' => ['active'], 'suspended' => ['suspended']];
    }

    #[DataProvider('allowedStatuses')]
    public function test_a_subscription_that_is_pending_active_or_suspended_can_take_a_drop(string $status): void
    {
        $subscription = $this->makeSubscription($this->company, $status);

        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();

        $this->assertSame($this->outputs[0]->id, $this->portOf($subscription->id));
    }

    public function test_a_terminated_subscription_cannot_take_a_drop(): void
    {
        $subscription = $this->makeSubscription($this->company, 'terminated');

        $this->drop($this->outputs[0], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('subscription_id');

        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
    }

    public function test_an_occupied_port_is_refused(): void
    {
        $holder = $this->makeSubscription($this->company);
        $other = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $holder->id)->assertRedirect();

        $this->drop($this->outputs[0], $other->id)->assertStatus(422)->assertJsonValidationErrors('port');

        $this->assertSame($holder->id, $this->outputs[0]->fresh()->subscription_id);
        $this->assertNull($this->portOf($other->id));
    }

    public function test_a_port_that_feeds_a_splitter_is_refused(): void
    {
        $this->splitter($this->net['odc'], 4, $this->outputs[1]);
        $subscription = $this->makeSubscription($this->company);

        $this->drop($this->outputs[1], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('port');
    }

    public function test_only_an_odp_output_port_can_carry_a_drop(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        [, $odcOutputs] = $this->splitter($this->net['odc'], 4, $pon[0]);
        $subscription = $this->makeSubscription($this->company);

        $this->drop($pon[0], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('port');
        $this->drop($odcOutputs[1], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('port');

        $this->assertNull($this->portOf($subscription->id));
    }

    public function test_a_drop_outside_the_serving_pop_is_refused(): void
    {
        $otherNet = $this->topology($this->company, 'B');
        [, $otherOutputs] = $this->splitter($otherNet['odp'], 4);
        $subscription = $this->makeSubscription($this->company, 'pending', $this->net['pop']);

        $this->drop($otherOutputs[0], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('port');
        $this->assertNull($this->portOf($subscription->id));

        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();
        $this->assertSame($this->outputs[0]->id, $this->portOf($subscription->id));
    }

    public function test_an_odp_without_a_location_cannot_serve_a_subscription_that_has_a_pop(): void
    {
        $floating = $this->makeAsset($this->company, 'odp', null, ['code' => 'ODP-FLOAT']);
        [, $floatingOutputs] = $this->splitter($floating, 4);
        $withPop = $this->makeSubscription($this->company, 'pending', $this->net['pop']);
        $withoutPop = $this->makeSubscription($this->company);

        $this->drop($floatingOutputs[0], $withPop->id)->assertStatus(422)->assertJsonValidationErrors('port');
        $this->drop($floatingOutputs[0], $withoutPop->id)->assertRedirect();
    }

    public function test_the_pop_match_is_by_ancestry_not_by_code_prefix(): void
    {
        $lookalike = $this->makeLocation($this->company, 'POP-A2', 'pop');
        $rack = $this->makeLocation($this->company, 'RACK-A21', 'rack', $lookalike);
        $site = $this->makeLocation($this->company, 'SITE-A211', 'site', $rack);
        [, $lookalikeOutputs] = $this->splitter($this->odpAt($this->company, $site, 'ODP-A211'), 4);
        $subscription = $this->makeSubscription($this->company, 'pending', $this->net['pop']);

        $this->drop($lookalikeOutputs[0], $subscription->id)->assertStatus(422)->assertJsonValidationErrors('port');
    }

    public function test_moving_a_drop_releases_the_old_port_in_the_same_step(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();

        $this->drop($this->outputs[5], $subscription->id)->assertRedirect();

        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
        $this->assertSame($subscription->id, $this->outputs[5]->fresh()->subscription_id);
        $this->assertSame(1, OdnPort::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_a_refused_move_leaves_the_old_drop_in_place(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $holder = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();
        $this->drop($this->outputs[1], $holder->id)->assertRedirect();

        $this->drop($this->outputs[1], $subscription->id)->assertStatus(422);

        $this->assertSame($subscription->id, $this->outputs[0]->fresh()->subscription_id);
    }

    public function test_assigning_the_same_port_again_changes_nothing(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();
        $before = Activity::query()->where('log_name', 'odn')->count();

        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();

        $this->assertSame($this->outputs[0]->id, $this->portOf($subscription->id));
        $this->assertSame($before, Activity::query()->where('log_name', 'odn')->count());
    }

    public function test_another_companys_subscription_is_refused(): void
    {
        $foreign = $this->makeSubscription($this->otherCompany());

        $this->drop($this->outputs[0], $foreign->id)->assertStatus(422)->assertJsonValidationErrors('subscription_id');

        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
    }

    public function test_a_drop_is_released_through_the_route_and_the_port_is_free_again(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $other = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();

        $this->deleteJson(route('admin.odn.drops.destroy', $this->outputs[0]))->assertRedirect();

        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
        $this->drop($this->outputs[0], $other->id)->assertRedirect();
    }

    public function test_releasing_a_port_without_a_drop_is_422(): void
    {
        $this->deleteJson(route('admin.odn.drops.destroy', $this->outputs[0]))->assertStatus(422)->assertJsonValidationErrors('port');
    }

    public function test_assign_and_release_are_audited_with_subscription_port_and_odp(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->drop($this->outputs[0], $subscription->id)->assertRedirect();
        $this->drop($this->outputs[1], $subscription->id)->assertRedirect();
        $this->deleteJson(route('admin.odn.drops.destroy', $this->outputs[1]))->assertRedirect();

        $assigned = Activity::query()->where('log_name', 'odn')->where('description', 'drop_assigned')->orderBy('id')->get();
        $released = Activity::query()->where('log_name', 'odn')->where('description', 'drop_released')->firstOrFail();

        $this->assertCount(2, $assigned);
        $this->assertSame($subscription->id, $assigned[1]->properties['subscription_id']);
        $this->assertSame($this->outputs[1]->id, $assigned[1]->properties['port_id']);
        $this->assertSame($this->net['odp']->id, $assigned[1]->properties['odp_asset_id']);
        $this->assertSame($this->outputs[0]->id, $assigned[1]->properties['moved_from_port_id']);
        $this->assertSame($this->outputs[1]->id, $released->properties['port_id']);
        $this->assertSame($this->admin->id, $released->causer_id);
    }

    public function test_the_service_names_the_form_field_the_port_came_from(): void
    {
        $subscription = $this->makeSubscription($this->company);

        try {
            OdnService::assignDrop(999999, $subscription, 'subscription.odn_port_id');
            $this->fail('an unknown port must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subscription.odn_port_id', $exception->errors());
        }
    }

    public function test_release_for_subscription_frees_its_port_and_reports_whether_it_had_one(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $without = $this->makeSubscription($this->company);
        OdnService::assignDrop($this->outputs[0], $subscription);

        $this->assertTrue(OdnService::releaseForSubscription($subscription));
        $this->assertFalse(OdnService::releaseForSubscription($subscription));
        $this->assertFalse(OdnService::releaseForSubscription($without));

        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
        $this->assertSame('subscription terminated', Activity::query()->where('description', 'drop_released')->firstOrFail()->properties['reason']);
    }

    public function test_drop_port_for_describes_the_odp_and_its_location(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->assertNull(OdnService::dropPortFor($subscription));

        OdnService::assignDrop($this->outputs[3], $subscription);

        $this->assertSame([
            'port_id' => $this->outputs[3]->id,
            'number' => 4,
            'odp_asset_id' => $this->net['odp']->id,
            'odp_code' => 'ODP-A11',
            'odp_name' => $this->net['odp']->name,
            'location_path' => 'POP-A > RACK-A1 > SITE-A11',
        ], OdnService::dropPortFor($subscription));
    }
}
