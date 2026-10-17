<?php

namespace Tests\Feature\Odn;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Q14: admin and manager hold all three ODN permissions; staff may view and assign drops (they onboard
 * customers) but not manage the register; the technician only views; the customer persona holds none.
 */
class OdnPermissionTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const ENDPOINTS = [
        'view' => ['odn.index', 'odn.assets.show'],
        'manage' => ['pon-ports.store', 'splitters.store', 'splitters.update', 'splitters.destroy', 'ports.destroy'],
        'assign' => ['drops.store', 'drops.destroy'],
    ];

    private const ALLOWED = [
        'view' => ['admin', 'manager', 'staff', 'technician'],
        'manage' => ['admin', 'manager'],
        'assign' => ['admin', 'manager', 'staff'],
    ];

    /** @var array<string, mixed> */
    private array $net;

    private OdnSplitter $splitter;

    private OdnSplitter $freeSplitter;

    private OdnPort $freeOutput;

    private OdnPort $heldOutput;

    private OdnPort $freePon;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
        $this->net = $this->topology($this->company);
        $pon = $this->ponPorts($this->net['olt'], 2);
        $this->freePon = $pon[1];
        [$this->splitter, $outputs] = $this->splitter($this->net['odp'], 4, $pon[0]);
        [$this->freeSplitter] = $this->splitter($this->net['odc'], 2);
        [$this->heldOutput, $this->freeOutput] = [$outputs[0], $outputs[1]];
        $this->subscription = $this->makeSubscription($this->company);
        OdnService::assignDrop($this->heldOutput, $this->makeSubscription($this->company));
    }

    private function perform(string $endpoint): TestResponse
    {
        return match ($endpoint) {
            'odn.index' => $this->get(route('admin.odn.index')),
            'odn.assets.show' => $this->get(route('admin.odn.assets.show', $this->net['odp'])),
            'pon-ports.store' => $this->postJson(route('admin.odn.pon-ports.store', $this->net['olt']), ['count' => 2]),
            'splitters.store' => $this->postJson(route('admin.odn.splitters.store', $this->net['odc']), ['outputs' => 4]),
            'splitters.update' => $this->putJson(route('admin.odn.splitters.update', $this->splitter), ['label' => 'Renamed']),
            'splitters.destroy' => $this->deleteJson(route('admin.odn.splitters.destroy', $this->freeSplitter)),
            'ports.destroy' => $this->deleteJson(route('admin.odn.ports.destroy', $this->freePon)),
            'drops.store' => $this->postJson(route('admin.odn.drops.store', $this->freeOutput), ['subscription_id' => $this->subscription->public_id]),
            'drops.destroy' => $this->deleteJson(route('admin.odn.drops.destroy', $this->heldOutput)),
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
        $this->assertSame(2, OdnPort::query()->where('kind', 'pon')->count());
        $this->assertSame(6, OdnPort::query()->where('kind', 'splitter_out')->count());
        $this->assertSame(2, OdnSplitter::query()->count());
        $this->assertNotNull($this->heldOutput->fresh()->subscription_id);
        $this->assertNull($this->freeOutput->fresh()->subscription_id);
        $this->assertNull($this->splitter->fresh()->label);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->app['auth']->logout();

        $this->get(route('admin.odn.index'))->assertRedirect(route('login'));
        $this->postJson(route('admin.odn.drops.store', $this->freeOutput), [])->assertUnauthorized();
    }

    public function test_the_asset_page_flags_follow_the_role(): void
    {
        $this->actingAs($this->makeUser($this->company, 'staff'));
        $this->get(route('admin.odn.assets.show', $this->net['odp']))
            ->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.assign', true));

        $this->actingAs($this->makeUser($this->company, 'technician'));
        $this->get(route('admin.odn.assets.show', $this->net['odp']))
            ->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.assign', false));
    }
}
