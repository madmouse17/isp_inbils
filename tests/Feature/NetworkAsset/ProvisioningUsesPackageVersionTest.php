<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Database\Factories\SpeedProfileFactory;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageVersion;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Provisioning follows the version a subscription was sold under, not the package's current terms (spec
 * .scratch/master-lifecycle D3): editing the speed of a package appends a version, and the subscriptions that already
 * exist keep their RADIUS group and rate until staff move them to the new version.
 */
class ProvisioningUsesPackageVersionTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private string $realm;

    private string $nasIp;

    private ServicePackage $package;

    private ServiceSubscription $subscription;

    private NetworkAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->realm = $this->realmOf($this->company);
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $nas = $this->nasClient($this->company);
        $this->nasIp = (string) $nas->nasname;
        $this->coaAnswers($this->nasIp, 'ack');
        $this->package = $this->packageWithRadiusProfile($this->company, 'home-20m', 20, 10);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->package);
        $this->account = $this->networkAccountFor($this->subscription);
        $this->actingAs($this->admin);
    }

    /** The package moves to a faster speed profile: version 2, for new sales only. */
    private function speedUpThePackage(): ServicePackageVersion
    {
        $faster = SpeedProfileFactory::new()->create([
            'company_id' => $this->company->id,
            'name' => 'Speed 100',
            'radius_profile_name' => 'home-100m',
            'download_max_mbps' => 100,
            'upload_max_mbps' => 50,
        ]);

        $this->package->update(['speed_profile_id' => $faster->id]);

        return ServicePackageVersion::query()->where('service_package_id', $this->package->id)->orderByDesc('version')->firstOrFail();
    }

    private function group(string $user = 'alice'): ?string
    {
        return $this->radiusRows('radusergroup', ['username' => $user.'@'.$this->realm])[0]['groupname'] ?? null;
    }

    private function rateLimit(string $group): ?string
    {
        return collect($this->radiusRows('radgroupreply', ['groupname' => $group]))->pluck('value', 'attribute')['Mikrotik-Rate-Limit'] ?? null;
    }

    private function openSession(string $user = 'alice'): void
    {
        $this->seedRadiusSession(['username' => $user.'@'.$this->realm, 'nasipaddress' => $this->nasIp]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>} */
    private function radiusState(): array
    {
        return [$this->radiusRows('radcheck'), $this->radiusRows('radusergroup'), $this->radiusRows('radgroupreply')];
    }

    public function test_a_package_speed_change_leaves_a_subscription_on_the_group_it_was_sold_under(): void
    {
        $this->applyAccount($this->account);
        $this->openSession();
        $sold = $this->subscription->service_package_version_id;
        $before = $this->radiusState();

        $newest = $this->speedUpThePackage();
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();
        $this->applyAccount($this->account);

        $this->assertSame(2, $newest->version);
        $this->assertSame($sold, $this->subscription->fresh()->service_package_version_id);
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame('10M/20M', $this->rateLimit("{$this->realm}/home-20m"));
        $this->assertSame([], $this->radiusRows('radgroupreply', ['groupname' => "{$this->realm}/home-100m"]), 'the new rate is not written for an old subscription');
        $this->assertSame($before, $this->radiusState(), 'neither the sync nor a new run rewrote a thing');
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame("{$this->realm}/home-20m", $this->account->fresh()->provisioned_group);
    }

    public function test_a_subscription_sold_after_the_change_gets_the_new_group_and_the_old_one_keeps_its_own(): void
    {
        $this->applyAccount($this->account);
        $newest = $this->speedUpThePackage();

        $newer = $this->subscriptionIn($this->company, 'active', null, $this->package->fresh());
        $this->applyAccount($this->networkAccountFor($newer, ['username' => 'bob@'.$this->realm]));
        $this->applyAccount($this->account);

        $this->assertSame($newest->id, $newer->service_package_version_id);
        $this->assertSame("{$this->realm}/home-100m", $this->group('bob'));
        $this->assertSame('50M/100M', $this->rateLimit("{$this->realm}/home-100m"));
        $this->assertSame("{$this->realm}/home-20m", $this->group('alice'));
    }

    public function test_moving_the_subscription_to_the_new_version_rewrites_its_group_and_cuts_the_session(): void
    {
        $this->applyAccount($this->account);
        $this->openSession();
        $newest = $this->speedUpThePackage();

        $this->subscription->forceFill(['service_package_version_id' => $newest->id])->save();

        $this->assertSame("{$this->realm}/home-100m", $this->group());
        $this->assertSame('50M/100M', $this->rateLimit("{$this->realm}/home-100m"));
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_the_observer_queues_one_apply_job_when_only_the_version_changes(): void
    {
        Queue::fake();

        $newest = $this->speedUpThePackage();
        Queue::assertNotPushed(ApplyNetworkAccountJob::class);

        $this->subscription->forceFill(['service_package_version_id' => $newest->id])->save();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === $this->account->id && $job->companyId === (int) $this->company->id);
    }

    public function test_network_sync_loads_the_version_profiles_in_one_query_for_every_account(): void
    {
        $this->applyAccount($this->account);

        foreach (['bob', 'carol'] as $user) {
            $this->applyAccount($this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->package), ['username' => $user.'@'.$this->realm]));
        }

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $versionReads = collect(DB::connection()->getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `service_package_versions`'));
        $this->assertCount(1, $versionReads, 'three accounts, one read of their versions');
    }

    public function test_a_new_account_is_checked_against_the_profile_name_of_the_sold_version(): void
    {
        $sold = $this->subscriptionIn($this->company, 'active', null, $this->package);
        $unnamed = SpeedProfileFactory::new()->create(['company_id' => $this->company->id, 'name' => 'Speed unnamed', 'radius_profile_name' => null]);
        $this->package->update(['speed_profile_id' => $unnamed->id]);

        $this->post(route('admin.subscriptions.network-account.store', $sold), ['service_type' => 'pppoe', 'username' => 'dina.home'])->assertRedirect();

        $this->assertSame('synced', NetworkAccount::query()->where('subscription_id', $sold->id)->sole()->sync_status, 'the package has no name now, the sold version has one');
        $this->assertSame("{$this->realm}/home-20m", $this->group('dina.home'));
    }

    public function test_a_new_account_is_refused_while_the_sold_version_has_no_profile_name(): void
    {
        $nameless = $this->packageWithRadiusProfile($this->company, null);
        $sold = $this->subscriptionIn($this->company, 'active', null, $nameless);
        $named = SpeedProfileFactory::new()->create(['company_id' => $this->company->id, 'name' => 'Speed named', 'radius_profile_name' => 'home-30m']);
        $nameless->update(['speed_profile_id' => $named->id]);
        $url = route('admin.subscriptions.network-account.store', $sold);

        $this->postJson($url, ['service_type' => 'pppoe', 'username' => 'erik.home'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription' => 'Profil kecepatan paket ini belum punya nama profil RADIUS.']);
        $this->assertSame(0, NetworkAccount::query()->where('subscription_id', $sold->id)->count());

        $sold->forceFill(['service_package_version_id' => ServicePackageVersion::query()->where('service_package_id', $nameless->id)->orderByDesc('version')->value('id')])->saveQuietly();
        $this->postJson($url, ['service_type' => 'pppoe', 'username' => 'erik.home'])->assertRedirect();
    }

    public function test_the_account_page_shows_the_speed_profile_of_the_sold_version(): void
    {
        $this->speedUpThePackage();

        $this->get(route('admin.subscriptions.network-account.show', $this->subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.speed_profile.radius_profile_name', 'home-20m')
                ->where('subscription.speed_profile.name', fn (string $name): bool => $name !== 'Speed 100'));
    }
}
