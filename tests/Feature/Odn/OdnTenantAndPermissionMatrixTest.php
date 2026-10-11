<?php

namespace Tests\Feature\Odn;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/**
 * Ticket 07: the tenant boundary and the permission floor of every /admin/odn route, driven by the route
 * table so a route added later fails the completeness test until it is covered here too. A foreign
 * `odn_port_id` at onboarding is covered by IpamOdnLifecycleTest::test_another_companys_port_is_refused_and_nothing_is_created.
 */
class OdnTenantAndPermissionMatrixTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    /** No record in the URL, so it cannot be 404; the floor test covers it. */
    private const COLLECTION_ROUTES = ['admin.odn.index'];

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();

        $this->subscription = $this->makeSubscription($this->company);
    }

    /** @return array{olt: NetworkAsset, odc: NetworkAsset, odp: NetworkAsset, splitter: OdnSplitter, pon: OdnPort, output: OdnPort} */
    private function records(Company $company, string $suffix): array
    {
        $net = $this->topology($company, $suffix);

        return $this->inCompany($company, function () use ($net): array {
            $pon = $this->ponPorts($net['olt'], 2);
            [$splitter, $outputs] = $this->splitter($net['odp'], 4);

            return ['olt' => $net['olt'], 'odc' => $net['odc'], 'odp' => $net['odp'], 'splitter' => $splitter, 'pon' => $pon[0], 'output' => $outputs[0]];
        });
    }

    /**
     * Every ODN route, each sending a body that would be valid for the current company.
     *
     * @param  array{olt: NetworkAsset, odc: NetworkAsset, odp: NetworkAsset, splitter: OdnSplitter, pon: OdnPort, output: OdnPort}  $r
     * @return array<string, Closure(): TestResponse>
     */
    private function requests(array $r): array
    {
        return [
            'admin.odn.index' => fn () => $this->get(route('admin.odn.index')),
            'admin.odn.assets.show' => fn () => $this->get(route('admin.odn.assets.show', $r['olt'])),
            'admin.odn.pon-ports.store' => fn () => $this->postJson(route('admin.odn.pon-ports.store', $r['olt']), ['count' => 1]),
            'admin.odn.splitters.store' => fn () => $this->postJson(route('admin.odn.splitters.store', $r['odc']), ['outputs' => 4]),
            'admin.odn.splitters.update' => fn () => $this->putJson(route('admin.odn.splitters.update', $r['splitter']), ['label' => 'x']),
            'admin.odn.splitters.destroy' => fn () => $this->deleteJson(route('admin.odn.splitters.destroy', $r['splitter'])),
            'admin.odn.ports.destroy' => fn () => $this->deleteJson(route('admin.odn.ports.destroy', $r['pon'])),
            'admin.odn.drops.store' => fn () => $this->postJson(route('admin.odn.drops.store', $r['output']), ['subscription_id' => $this->subscription->id]),
            'admin.odn.drops.destroy' => fn () => $this->deleteJson(route('admin.odn.drops.destroy', $r['output'])),
        ];
    }

    /** @return array{0: int, 1: int, 2: int} ports, splitters and ports held by a subscription */
    private function footprint(): array
    {
        return [
            OdnPort::withoutCompany()->count(),
            OdnSplitter::withoutCompany()->count(),
            OdnPort::withoutCompany()->whereNotNull('subscription_id')->count(),
        ];
    }

    public function test_every_odn_route_has_an_entry_in_this_matrix(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'admin.odn.'))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            $registered,
            array_keys($this->requests($this->records($this->company, 'A'))),
            'A route was added or removed: give it a tenant and a permission check here.',
        );
    }

    public function test_every_record_bound_odn_route_is_404_for_another_companys_record(): void
    {
        $foreign = $this->records($this->otherCompany(), 'X');
        $before = $this->footprint();

        foreach ($this->requests($foreign) as $name => $send) {
            if (in_array($name, self::COLLECTION_ROUTES, true)) {
                continue;
            }

            $this->assertSame(404, $send()->getStatusCode(), $name);
        }

        $this->assertSame($before, $this->footprint());
        $this->assertNull($foreign['output']->fresh()->subscription_id);
    }

    public function test_a_user_without_odn_permissions_gets_403_on_every_route_and_changes_nothing(): void
    {
        $mine = $this->records($this->company, 'A');
        $before = $this->footprint();

        $roleless = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        foreach ([$this->makeUser($this->company, 'customer'), $roleless] as $user) {
            $this->actingAs($user);

            foreach ($this->requests($mine) as $name => $send) {
                $this->assertSame(403, $send()->getStatusCode(), "{$name} for user {$user->id}");
            }
        }

        $this->assertSame($before, $this->footprint());
    }

    public function test_the_technician_reads_every_page_and_writes_nothing(): void
    {
        $mine = $this->records($this->company, 'A');
        $before = $this->footprint();
        $this->actingAs($this->makeUser($this->company, 'technician'));

        foreach ($this->requests($mine) as $name => $send) {
            $expected = in_array($name, ['admin.odn.index', 'admin.odn.assets.show'], true) ? 200 : 403;

            $this->assertSame($expected, $send()->getStatusCode(), $name);
        }

        $this->assertSame($before, $this->footprint());
    }

    public function test_another_companys_id_in_the_body_is_refused_and_nothing_is_written(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->records($other, 'X');
        $foreignSubscription = $this->makeSubscription($other);
        $mine = $this->records($this->company, 'A');
        $before = $this->footprint();

        $cases = [
            'input_port_id on a new splitter' => [fn () => $this->postJson(route('admin.odn.splitters.store', $mine['odc']), ['outputs' => 4, 'input_port_id' => $foreign['pon']->id]), 'input_port_id'],
            'input_port_id on a splitter update' => [fn () => $this->putJson(route('admin.odn.splitters.update', $mine['splitter']), ['input_port_id' => $foreign['pon']->id]), 'input_port_id'],
            'subscription_id on a drop' => [fn () => $this->postJson(route('admin.odn.drops.store', $mine['output']), ['subscription_id' => $foreignSubscription->id]), 'subscription_id'],
        ];

        foreach ($cases as $label => [$send, $field]) {
            $response = $send();

            $this->assertSame(422, $response->getStatusCode(), $label);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame($before, $this->footprint());
        $this->assertNull($mine['splitter']->fresh()->input_port_id);
        $this->assertNull($mine['output']->fresh()->subscription_id);
        $this->assertNull($foreign['pon']->fresh()->subscription_id);
    }
}
