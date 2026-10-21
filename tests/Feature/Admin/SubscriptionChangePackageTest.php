<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\ServicePackageVersion;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * A subscription keeps the terms of the package version it was sold under (spec .scratch/master-lifecycle D3): editing
 * a package appends a version and moves nobody. Staff move one subscription at a time to the latest version of a
 * package, its own or another, with "Ganti paket" (SubscriptionController::changePackage); the edit form no longer does.
 */
class SubscriptionChangePackageTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const SAME_VERSION = 'Langganan sudah memakai versi terbaru paket ini.';

    private const TERMINATED = 'Langganan yang sudah berhenti tidak bisa pindah paket.';

    private const INACTIVE = 'Pilih paket layanan yang masih aktif.';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
    }

    /** A package that sells for 200.000 a month and 50.000 to install: version 1, on a speed profile of its own. */
    private function package(array $attributes = []): ServicePackage
    {
        return ServicePackageFactory::new()->create([
            'company_id' => $this->company->id,
            'price_mrc' => 200000,
            'price_otc' => 50000,
            'speed_profile_id' => $this->speedProfile()->id,
            ...$attributes,
        ]);
    }

    private function speedProfile(int $downloadMax = 50): SpeedProfile
    {
        return SpeedProfile::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Speed '.Str::random(10),
            'radius_profile_name' => 'p-'.Str::random(8),
            'download_max_mbps' => $downloadMax,
            'upload_max_mbps' => intdiv($downloadMax, 2),
        ]);
    }

    /** The package moves on: a new monthly price, a new installation fee and a faster speed profile are its next version. */
    private function revise(ServicePackage $package, int $mrc = 300000, int $otc = 80000): ServicePackageVersion
    {
        $package->update(['price_mrc' => $mrc, 'price_otc' => $otc, 'speed_profile_id' => $this->speedProfile(100)->id]);

        return ServicePackageVersion::query()->where('service_package_id', $package->id)->orderByDesc('version')->firstOrFail();
    }

    /** A subscription billed what its package costs now, on version 1. */
    private function subscriptionOn(ServicePackage $package, string $status = 'active'): ServiceSubscription
    {
        $subscription = $this->subscriptionIn($this->company, $status, null, $package);
        $subscription->forceFill(['mrc_amount' => $package->price_mrc, 'otc_installation_fee' => $package->price_otc])->saveQuietly();

        return $this->freshSubscription($subscription);
    }

    /**
     * @param  ServicePackage|string|null  $package  a package, or the id string a client might send
     * @param  array<string, mixed>  $fields
     */
    private function move(ServiceSubscription $subscription, ServicePackage|string|null $package, array $fields = [], ?User $by = null, bool $json = false): TestResponse
    {
        $url = route('admin.subscriptions.change-package', $subscription);
        $body = ['service_package_id' => $package instanceof ServicePackage ? $package->public_id : $package, ...$fields];
        $actor = $this->actingAs($by ?? $this->admin);

        return $json
            ? $actor->postJson($url, $body)
            : $actor->from(route('admin.subscriptions.show', $subscription))->post($url, $body);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($user, $role);

        return $user;
    }

    /** @return array{0: int, 1: int, 2: string, 3: string} package, version, monthly fee and installation fee as stored */
    private function stored(ServiceSubscription $subscription): array
    {
        $row = $this->freshSubscription($subscription);

        return [$row->service_package_id, $row->service_package_version_id, $row->mrc_amount, $row->otc_installation_fee];
    }

    private function loggedMoves(): int
    {
        return Activity::query()->where('log_name', 'service_subscription')->where('description', 'package_changed')->count();
    }

    public function test_editing_a_package_leaves_existing_subscriptions_on_the_terms_they_were_sold_with(): void
    {
        $package = $this->package();
        $soldSpeed = $package->speedProfile;
        $subscription = $this->subscriptionOn($package);
        $sold = $subscription->service_package_version_id;

        $newest = $this->revise($package);

        $row = $this->freshSubscription($subscription);
        $this->assertSame(2, $newest->version);
        $this->assertSame($sold, $row->service_package_version_id);
        $this->assertSame('200000.00', $row->mrc_amount);
        $this->assertSame('50000.00', $row->otc_installation_fee);
        $this->assertSame('200000.00', $row->packageVersion->price_mrc);
        $this->assertSame($soldSpeed->id, $row->packageVersion->speed_profile_id);
        $this->assertSame(0, $this->loggedMoves());
    }

    public function test_the_page_shows_the_version_a_subscription_sits_on_and_whether_it_is_the_latest(): void
    {
        $package = $this->package();
        $soldSpeed = $package->speedProfile;
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);

        $this->actingAs($this->admin)->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.data.package_version.version', 1)
                ->has('subscription.data.package_version.valid_from')
                ->where('subscription.data.package_version.price_mrc', '200000.00')
                ->where('subscription.data.package_version.price_otc', '50000.00')
                ->where('subscription.data.package_version.speed_profile.name', $soldSpeed->name)
                ->where('subscription.data.package_version.speed_profile.download_max_mbps', 50)
                ->has('subscription.data.package_version.bandwidth_profile.name')
                ->has('subscription.data.package_version.sla_tier.name')
                ->has('subscription.data.package_version.category.name')
                ->where('subscription.data.package_version_is_latest', false)
                ->where('subscription.data.package.id', $package->public_id)
                ->where('subscription.data.package.price_mrc', '300000.00')
                ->where('can.changePackage', true)
                ->missing('packageOptions'));

        $this->move($subscription, $package)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.data.package_version.version', 2)
                ->where('subscription.data.package_version.price_mrc', '300000.00')
                ->where('subscription.data.package_version.speed_profile.download_max_mbps', 100)
                ->where('subscription.data.package_version_is_latest', true));
    }

    public function test_package_options_list_the_active_packages_and_load_only_when_asked_for(): void
    {
        $echo = $this->package(['name' => 'Echo 100', 'price_mrc' => 350000]);
        $alpha = $this->package(['name' => 'Alpha 20', 'price_mrc' => 150000]);
        $this->package(['name' => 'Bravo off', 'is_active' => false]);
        $this->package(['name' => 'Charlie gone'])->delete();
        ServicePackageFactory::new()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'name' => 'Delta foreign']);
        $subscription = $this->subscriptionOn($alpha);

        $this->actingAs($this->admin)->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->missing('packageOptions'));

        $this->actingAs($this->admin)->get(route('admin.subscriptions.show', $subscription), $this->partialReload('Admin/Subscriptions/Show', 'packageOptions'))
            ->assertOk()
            ->assertJsonPath('props.packageOptions', [
                ['id' => $alpha->public_id, 'code' => $alpha->code, 'name' => 'Alpha 20', 'price_mrc' => '150000.00'],
                ['id' => $echo->public_id, 'code' => $echo->code, 'name' => 'Echo 100', 'price_mrc' => '350000.00'],
            ]);
    }

    public function test_change_package_moves_to_the_latest_version_and_logs(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $neighbour = $this->subscriptionOn($package);
        $neighbourBefore = $this->stored($neighbour);
        $newest = $this->revise($package);

        $this->move($subscription, $package)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.subscriptions.show', $subscription))
            ->assertSessionHas('success', 'Langganan dipindahkan ke versi terbaru paket.');

        $this->assertSame([$package->id, $newest->id, '300000.00', '50000.00'], $this->stored($subscription));
        $this->assertSame($neighbourBefore, $this->stored($neighbour), 'only the subscription that was named moves');

        $log = Activity::query()
            ->where('log_name', 'service_subscription')
            ->where('description', 'package_changed')
            ->where('subject_type', ServiceSubscription::class)
            ->where('subject_id', $subscription->id)
            ->sole();
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame($subscription->code, $log->properties['code']);
        $this->assertSame(['package' => $package->code, 'version' => 1, 'mrc_amount' => '200000.00', 'otc_installation_fee' => '50000.00'], $log->properties['from']);
        $this->assertSame(['package' => $package->code, 'version' => 2, 'mrc_amount' => '300000.00', 'otc_installation_fee' => '50000.00'], $log->properties['to']);
        $this->assertSame(1, $this->loggedMoves());
    }

    public function test_a_subscription_can_move_to_another_package_at_its_latest_version(): void
    {
        $current = $this->package();
        $target = $this->package(['name' => 'Fiber 100', 'price_mrc' => 400000, 'price_otc' => 90000]);
        $newest = $this->revise($target, 450000, 95000);
        $subscription = $this->subscriptionOn($current);

        $this->move($subscription, $target)->assertSessionHasNoErrors();

        $row = $this->freshSubscription($subscription);
        $this->assertSame($target->id, $row->service_package_id);
        $this->assertSame($newest->id, $row->service_package_version_id);
        $this->assertSame($target->id, $row->packageVersion->service_package_id);
        $this->assertSame('450000.00', $row->mrc_amount);
        $this->assertSame('50000.00', $row->otc_installation_fee, 'an installed subscription keeps its installation fee');

        $this->actingAs($this->admin)->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.data.package.id', $target->public_id)
                ->where('subscription.data.service_package_id', $target->public_id));
    }

    public function test_the_monthly_fee_defaults_to_the_version_price_and_accepts_an_override(): void
    {
        $package = $this->package();
        $byDefault = $this->subscriptionOn($package);
        $overridden = $this->subscriptionOn($package);
        $free = $this->subscriptionOn($package);
        $this->revise($package);

        $this->move($byDefault, $package)->assertSessionHasNoErrors();
        $this->move($overridden, $package, ['mrc_amount' => '275000.50'])->assertSessionHasNoErrors();
        $this->move($free, $package, ['mrc_amount' => 0])->assertSessionHasNoErrors();

        $this->assertSame('300000.00', $this->freshSubscription($byDefault)->mrc_amount);
        $this->assertSame('275000.50', $this->freshSubscription($overridden)->mrc_amount);
        $this->assertSame('0.00', $this->freshSubscription($free)->mrc_amount, 'zero is a fee the staff may choose');
    }

    public function test_the_installation_fee_changes_only_while_the_subscription_is_pending(): void
    {
        $package = $this->package();
        $waiting = $this->subscriptionOn($package, 'pending');
        $running = $this->subscriptionOn($package, 'active');
        $this->revise($package, 300000, 80000);

        $this->move($waiting, $package)->assertSessionHasNoErrors();
        $this->move($running, $package)->assertSessionHasNoErrors();

        $this->assertSame('80000.00', $this->freshSubscription($waiting)->otc_installation_fee);
        $this->assertSame('50000.00', $this->freshSubscription($running)->otc_installation_fee);
        $this->assertSame('300000.00', $this->freshSubscription($waiting)->mrc_amount);
        $this->assertSame('300000.00', $this->freshSubscription($running)->mrc_amount);
    }

    public function test_a_terminated_subscription_or_one_on_the_latest_version_is_refused_with_422(): void
    {
        $package = $this->package();
        $current = $this->subscriptionOn($package);
        $this->networkAccountFor($current);
        Queue::fake();

        $this->move($current, $package, json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_package_id' => self::SAME_VERSION]);

        $ended = $this->subscriptionOn($package, 'terminated');
        $this->networkAccountFor($ended, ['username' => 'ended@'.$this->realmOf($this->company)]);
        $this->revise($package);
        $before = [$this->stored($current), $this->stored($ended)];

        $this->move($ended, $package, json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_package_id' => self::TERMINATED]);

        $this->assertSame($before, [$this->stored($current), $this->stored($ended)]);
        $this->assertSame(0, $this->loggedMoves());
        Queue::assertNothingPushed();
    }

    public function test_an_inactive_target_package_is_rejected_and_nothing_moves(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);
        $inactive = $this->package(['is_active' => false]);
        $before = $this->stored($subscription);

        $this->move($subscription, $inactive, json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_package_id' => self::INACTIVE]);

        $this->assertSame($before, $this->stored($subscription));
        $this->assertSame(0, $this->loggedMoves());
    }

    public function test_a_package_of_another_company_or_an_unknown_one_answers_not_found(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = ServicePackageFactory::new()->create(['company_id' => $other->id]);
        $before = $this->stored($subscription);

        foreach (['another company' => $foreign->public_id, 'unknown id' => PublicId::new(), 'not an id' => 'not-a-ulid', 'integer id' => (string) $package->id] as $case => $id) {
            $this->assertSame(404, $this->move($subscription, $id, json: true)->getStatusCode(), $case);
        }

        $this->assertSame($before, $this->stored($subscription));
        $this->assertSame(0, $this->loggedMoves());
    }

    public function test_a_subscription_of_another_company_answers_not_found(): void
    {
        $package = $this->package();
        $this->revise($package);
        $foreign = $this->subscriptionIn(Company::factory()->create(['is_active' => true]));
        $before = $this->stored($foreign);

        $this->move($foreign, $package)->assertNotFound();

        $this->assertSame($before, $this->stored($foreign));
    }

    public function test_the_move_needs_the_manage_permission_and_the_page_hides_it_without(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);
        $staff = $this->userWithRole('staff');
        $before = $this->stored($subscription);

        $this->move($subscription, $package, by: $staff)->assertForbidden();
        $this->move($subscription, $package, by: $staff, json: true)->assertForbidden();

        $this->assertSame($before, $this->stored($subscription));
        $this->assertSame(0, $this->loggedMoves());

        $this->actingAs($staff)->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.changePackage', false)->missing('packageOptions'));
        $this->actingAs($staff)->get(route('admin.subscriptions.show', $subscription), $this->partialReload('Admin/Subscriptions/Show', 'packageOptions'))
            ->assertOk()
            ->assertJsonPath('props.packageOptions', null);
    }

    public function test_the_package_is_required_and_the_fee_must_be_an_amount(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);

        $this->move($subscription, null, json: true)->assertUnprocessable()->assertJsonValidationErrors('service_package_id');

        foreach (['abc', -1, '10000000000000000'] as $amount) {
            $this->move($subscription, $package, ['mrc_amount' => $amount], json: true)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('mrc_amount');
        }

        $this->assertSame(0, $this->loggedMoves());
    }

    public function test_the_radius_account_is_queued_for_one_reapply(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $account = $this->networkAccountFor($subscription);
        $this->revise($package);
        Queue::fake();

        $this->move($subscription, $package)->assertSessionHasNoErrors();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === $account->id && $job->companyId === (int) $this->company->id);
    }

    public function test_the_reapply_waits_for_the_surrounding_transaction_to_commit(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->networkAccountFor($subscription);
        $this->revise($package);
        Queue::fake();

        try {
            DB::transaction(function () use ($subscription, $package): void {
                SubscriptionService::changePackage($subscription, $package->id);
                Queue::assertNothingPushed();

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->freshSubscription($subscription)->packageVersion->version, 'the rollback took the move with it');

        DB::transaction(function () use ($subscription, $package): void {
            SubscriptionService::changePackage($subscription, $package->id);
            Queue::assertNothingPushed();
        });

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
    }

    public function test_the_move_locks_the_customer_row_before_it_touches_the_subscription(): void
    {
        $package = $this->package();
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);

        DB::enableQueryLog();

        try {
            SubscriptionService::changePackage($subscription, $package->id);
            $queries = array_map(fn (array $query): string => trim($query['query']), DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $customerLock = $subscriptionTouch = $subscriptionLock = null;

        foreach ($queries as $index => $sql) {
            $locks = str_ends_with($sql, 'for update');
            $customerLock ??= $locks && str_contains($sql, 'from `customers`') ? $index : null;
            $subscriptionTouch ??= str_contains($sql, '`service_subscriptions`') ? $index : null;
            $subscriptionLock ??= $locks && str_contains($sql, 'from `service_subscriptions`') ? $index : null;
        }

        $this->assertNotNull($customerLock, 'the move locks the customer row');
        $this->assertNotNull($subscriptionLock, 'the move locks the subscription row');
        $this->assertLessThan($subscriptionTouch, $customerLock, 'the customer row is locked before any subscription is read');
        $this->assertSame($subscriptionLock, $subscriptionTouch, 'the first read of the subscription is the locking read');
    }

    public function test_update_no_longer_changes_the_package(): void
    {
        $package = $this->package();
        $other = $this->package(['name' => 'Other package']);
        $subscription = $this->subscriptionOn($package);
        $this->revise($package);
        $before = $this->stored($subscription);

        $this->actingAs($this->admin)
            ->put(route('admin.subscriptions.update', $subscription), [
                'service_package_id' => $other->public_id,
                'notes' => 'Catatan baru',
                OptimisticLock::FIELD => OptimisticLock::version($subscription->fresh()),
            ])
            ->assertSessionHasNoErrors();

        $row = $this->freshSubscription($subscription);
        $this->assertSame('Catatan baru', $row->notes);
        $this->assertSame($before, $this->stored($subscription), 'the edit form does not move the package, the version or the fees');
        $this->assertSame($package->id, $row->service_package_id);
    }

    public function test_a_new_subscription_cannot_be_sold_on_an_inactive_package(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'label' => Str::random(12)]);
        $inactive = $this->package(['is_active' => false]);
        $active = $this->package();
        $this->revise($active);
        $url = route('admin.customers.subscriptions.store', $customer);

        $this->actingAs($this->admin)
            ->postJson($url, ['service_package_id' => $inactive->public_id, 'installation_address_id' => $address->public_id, 'billing_day' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_package_id' => self::INACTIVE]);
        $this->assertSame(0, ServiceSubscription::query()->where('customer_id', $customer->id)->count());

        $this->actingAs($this->admin)
            ->post($url, ['service_package_id' => $active->public_id, 'installation_address_id' => $address->public_id, 'billing_day' => 5])
            ->assertSessionHasNoErrors();
        $sold = ServiceSubscription::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(2, $sold->packageVersion->version, 'a new sale starts on the latest version');
        $this->assertSame('300000.00', $sold->mrc_amount);
    }

    public function test_customer_api_subscription_reads_version_terms(): void
    {
        $companyId = $this->company->id;
        $package = $this->package([
            'name' => 'Fiber 20',
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['company_id' => $companyId, 'name' => 'BW 20', 'download_mbps' => 20, 'upload_mbps' => 10])->id,
            'sla_tier_id' => SLATier::factory()->create(['company_id' => $companyId, 'name' => 'Silver SLA', 'response_time_hours' => 8, 'resolution_time_hours' => 48])->id,
            'service_package_category_id' => ($soldCategory = ServicePackageCategory::factory()->create(['company_id' => $companyId, 'code' => 'RMH', 'name' => 'Rumahan']))->id,
        ]);
        [$user, $customer] = $this->customerLogin($this->company);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'label' => Str::random(12)])->id,
            'status' => 'active',
            'mrc_amount' => 200000,
        ]);
        $token = $this->issueToken($user, 'customer');
        $read = fn () => $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $token)->assertOk()->json('data');

        // The package becomes a faster one under a better SLA in another category, and is renamed.
        $newCategory = ServicePackageCategory::factory()->create(['company_id' => $companyId, 'code' => 'BIS', 'name' => 'Bisnis']);
        $package->update([
            'name' => 'Fiber 100 Plus',
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['company_id' => $companyId, 'name' => 'BW 100', 'download_mbps' => 100, 'upload_mbps' => 50])->id,
            'speed_profile_id' => $this->speedProfile(200)->id,
            'sla_tier_id' => SLATier::factory()->create(['company_id' => $companyId, 'name' => 'Gold SLA', 'response_time_hours' => 2, 'resolution_time_hours' => 12])->id,
            'service_package_category_id' => $newCategory->id,
        ]);

        $before = $read();
        $this->assertSame(['id' => $package->public_id, 'name' => 'Fiber 100 Plus'], array_intersect_key($before['package'], array_flip(['id', 'name'])), 'the package name stays live');
        $this->assertSame(['id' => $soldCategory->public_id, 'name' => 'Rumahan'], $before['package']['category']);
        $this->assertSame([20, 10, 'Hingga 50 Mbps'], [$before['package']['download_mbps'], $before['package']['upload_mbps'], $before['package']['speed_label']]);
        $this->assertSame(['name' => 'Silver SLA', 'response_time_hours' => 8, 'resolution_time_hours' => 48], $before['sla_tier']);

        SubscriptionService::changePackage($subscription, $package->id);

        $after = $read();
        $this->assertSame(['id' => $newCategory->public_id, 'name' => 'Bisnis'], $after['package']['category']);
        $this->assertSame([100, 50, 'Hingga 200 Mbps'], [$after['package']['download_mbps'], $after['package']['upload_mbps'], $after['package']['speed_label']]);
        $this->assertSame(['name' => 'Gold SLA', 'response_time_hours' => 2, 'resolution_time_hours' => 12], $after['sla_tier']);
    }
}
