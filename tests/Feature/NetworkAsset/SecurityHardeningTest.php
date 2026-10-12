<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/** Fixes of the security review of the provisioning lane: secrets never leave the places meant for them. */
class SecurityHardeningTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->nas = $this->nasClient($this->company);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->actingAs($this->admin);
    }

    // The asset address is no longer a credential matter: the RADIUS secret is never sent to it.

    public function test_an_asset_editor_without_nas_manage_may_change_the_address_of_a_registered_router(): void
    {
        $editor = $this->userWithPermissions($this->company, 'network_asset.view', 'network_asset.update');
        $asset = $this->nas->networkAsset;
        $this->actingAs($editor);

        $this->put(route('admin.network-assets.update', $asset), ['name' => $asset->name, 'asset_type' => 'router', 'management_ip' => '192.0.2.201'])->assertRedirect();

        $this->assertSame('192.0.2.201', $asset->fresh()->getAttribute('management_ip'));
        $this->assertSame($this->nas->nasname, $this->nas->fresh()->nasname, 'the RADIUS client address is a NAS field and stays');
    }

    public function test_an_asset_that_is_not_a_nas_router_keeps_its_address_editable(): void
    {
        $plain = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'router', 'management_ip' => '192.0.2.210']);
        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.view', 'network_asset.update'));

        $this->put(route('admin.network-assets.update', $plain), ['name' => $plain->name, 'asset_type' => 'router', 'management_ip' => '192.0.2.211'])->assertRedirect();

        $this->assertSame('192.0.2.211', $plain->fresh()->getAttribute('management_ip'));
    }

    // The RADIUS shared secret never lands in the session as old input.

    public function test_a_failed_nas_form_never_flashes_the_radius_secret_into_the_session(): void
    {
        $asset = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'router', 'management_ip' => '192.0.2.150']);

        $this->post(route('admin.nas-devices.store'), ['network_asset_id' => $asset->id, 'nasname' => '192.0.2.150', 'coa_port' => 70000, 'radius_secret' => 'fake-flash-me-1'])
            ->assertSessionHasErrors('coa_port');
        $this->assertSame('192.0.2.150', session('_old_input.nasname'));
        $this->assertNull(session('_old_input.radius_secret'));

        $this->put(route('admin.nas-devices.update', $this->nas), ['coa_port' => 70000, 'radius_secret' => 'fake-flash-me-2'])
            ->assertSessionHasErrors('coa_port');
        $this->assertNull(session('_old_input.radius_secret'));
        $this->assertStringNotContainsString('fake-flash-me', json_encode(session()->all()));
    }

    public function test_a_failed_account_form_never_flashes_the_subscriber_secret_into_the_session(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), ['service_type' => 'l2tp', 'username' => 'valid.name', 'password' => 'fake-flash-me-3'])
            ->assertSessionHasErrors('service_type');

        $this->assertNull(session('_old_input.password'));
        $this->assertStringNotContainsString('fake-flash-me-3', json_encode(session()->all()));
    }

    // The one-time secret never sits in the session in clear.

    public function test_the_one_time_secret_never_sits_in_the_session_in_clear(): void
    {
        $show = route('admin.subscriptions.network-account.show', $this->subscription);

        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), ['service_type' => 'pppoe', 'username' => 'erin'])->assertRedirect($show);
        $secret = NetworkAccount::query()->where('username', 'like', 'erin@%')->firstOrFail()->password;
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));

        $page = $this->get($show);
        $this->assertSame($secret, $page->inertiaPage()['flash']['network_account']['secret']);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));

        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect($show);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->assertSame($secret, $this->get($show)->inertiaPage()['flash']['network_account']['secret']);
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
    }

    /** @return array<string, array{0: string}> */
    public static function oneTimeSecretSources(): array
    {
        return ['after create' => ['create'], 'after reveal' => ['reveal']];
    }

    #[DataProvider('oneTimeSecretSources')]
    public function test_a_stale_inertia_version_keeps_the_one_time_secret_sealed_until_the_next_valid_load(string $source): void
    {
        $show = route('admin.subscriptions.network-account.show', $this->subscription);
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), ['service_type' => 'pppoe', 'username' => 'erin'])->assertRedirect($show);
        $secret = NetworkAccount::query()->where('username', 'like', 'erin@%')->firstOrFail()->password;

        if ($source === 'reveal') {
            $this->get($show); // the display after the create is used up
            $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect($show);
        }

        // After an asset deployment the browser's next visit carries the old version: Inertia answers 409 and reflashes the session.
        foreach ([1, 2] as $_) {
            $this->get($show, ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-stale-asset-version'])->assertStatus(409);
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        }

        $this->assertSame($secret, $this->get($show)->inertiaPage()['flash']['network_account']['secret']);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
    }
}
