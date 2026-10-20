<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * K18: the PPPoE account of an installation is made when its SPK is first assigned, so the technician has the login on
 * site before the review. An installation that never happens must not leave that login working: cancelling the last
 * installation SPK of a pending subscription takes its RADIUS user away, and a new assignment brings it back.
 */
class InstallationNetworkAccountTest extends TestCase
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

    private NasDevice $nas;

    private ServiceSubscription $subscription;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->realm = $this->realmOf($this->company);
        $this->fakeRadius();
        $this->nas = $this->nasClient($this->company);
        $this->coaAnswers((string) $this->nas->nasname, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->actingAs($this->admin);
    }

    private function technician(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function spk(string $type = 'installation', string $status = 'generated', ?ServiceSubscription $subscription = null, array $attributes = []): WorkOrder
    {
        $subscription ??= $this->subscription;

        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            'type' => $type,
            'title' => 'Pasang baru',
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function assign(WorkOrder $workOrder, User $technician): WorkOrder
    {
        $workOrder = $workOrder->fresh();

        return SpkService::assign($workOrder, $technician->id, $this->admin->id, $workOrder->assigned_to, $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    private function account(?ServiceSubscription $subscription = null): ?NetworkAccount
    {
        return NetworkAccount::query()->where('subscription_id', ($subscription ?? $this->subscription)->id)->first();
    }

    private function username(?ServiceSubscription $subscription = null, string $suffix = ''): string
    {
        return strtolower(($subscription ?? $this->subscription)->customer->code).$suffix.'@'.$this->realm;
    }

    /** @return list<string> the usernames RADIUS holds a password for */
    private function radiusUsers(): array
    {
        return array_column($this->radiusRows('radcheck'), 'username');
    }

    private function sync(): void
    {
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'network_account')->where('description', $event)->count();
    }

    public function test_first_assignment_creates_pppoe_account_from_customer_code(): void
    {
        $workOrder = $this->spk();

        $this->assign($workOrder, $this->technician());

        $account = $this->account();
        $this->assertNotNull($account);
        $this->assertSame($this->username(), $account->username);
        $this->assertSame('pppoe', $account->service_type);
        $this->assertSame(12, strlen($account->password), 'a generated login secret, never typed by anyone');

        $created = Activity::query()->where('log_name', 'network_account')->where('description', 'created')->sole();
        $this->assertSame('installation_spk', $created->getExtraProperty('via'));
        $this->assertSame($workOrder->id, $created->getExtraProperty('work_order_id'));
        $this->assertNotContains($account->password, array_map('strval', $created->properties->flatten()->all()), 'the audit row never carries the secret');

        // The subscription is pending and pending is served: the user is in RADIUS at once, so the technician can test the link.
        $this->assertSame([$this->username()], $this->radiusUsers());
        $this->assertSame('enabled', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
    }

    public function test_reassignment_does_not_create_a_second_account(): void
    {
        $workOrder = $this->spk();
        $first = $this->technician();
        $second = $this->technician();

        $this->assign($workOrder, $first);
        $accountId = $this->account()->id;
        $this->assign($workOrder, $second);

        $this->assertSame(1, NetworkAccount::query()->count());
        $this->assertSame($accountId, $this->account()->id);
        $this->assertSame(1, $this->auditCount('created'));
        $this->assertSame($second->id, $workOrder->fresh()->assigned_to);
    }

    public function test_reassigning_to_another_technician_rotates_the_secret_audited_without_it_and_queues_the_apply(): void
    {
        $workOrder = $this->spk();
        $this->assign($workOrder, $this->technician());
        $before = $this->account()->password;
        Queue::fake([ApplyNetworkAccountJob::class]);

        $this->assign($workOrder, $this->technician());

        $after = $this->account()->password;
        $this->assertNotSame($before, $after);
        Queue::assertPushed(ApplyNetworkAccountJob::class);
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'secret_rotated')->sole();
        $this->assertSame('installation_reassigned', $audit->getExtraProperty('reason'));
        $this->assertStringNotContainsString($before, (string) $audit->properties);
        $this->assertStringNotContainsString($after, (string) $audit->properties);
    }

    public function test_naming_the_same_technician_again_or_reassigning_a_non_pending_subscription_keeps_the_secret(): void
    {
        $workOrder = $this->spk();
        $technician = $this->technician();
        $this->assign($workOrder, $technician);
        $before = $this->account()->password;

        $this->assign($workOrder, $technician);
        $this->assertSame($before, $this->account()->password);

        $this->subscription->forceFill(['status' => 'active'])->saveQuietly();
        $this->assign($workOrder, $this->technician());
        $this->assertSame($before, $this->account()->password);
        $this->assertSame(0, $this->auditCount('secret_rotated'));
    }

    public function test_reassigning_a_non_installation_spk_rotates_nothing(): void
    {
        $account = $this->networkAccountFor($this->subscription, ['username' => $this->username(suffix: '.manual')]);
        $before = $account->password;
        $workOrder = $this->spk('maintenance');

        $this->assign($workOrder, $this->technician());
        $this->assign($workOrder, $this->technician());

        $this->assertSame($before, $account->fresh()->password);
        $this->assertSame(0, $this->auditCount('secret_rotated'));
    }

    public function test_approval_creates_missing_account(): void
    {
        $technician = $this->technician();
        $creator = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $workOrder = $this->spk(status: 'waiting_review', attributes: ['assigned_to' => $technician->id, 'created_by' => $creator->id, 'submitted_at' => now()]);
        $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $technician->id])
            ->toMediaCollection('evidence', 'public');
        $this->assertNull($this->account(), 'the guard refused at assignment, or the SPK was assigned before this release');

        SpkService::approve($workOrder);

        $this->assertSame('active', $this->subscription->fresh()->status);
        $this->assertSame($this->username(), $this->account()->username);
        $this->assertSame('installation_spk', Activity::query()->where('log_name', 'network_account')->where('description', 'created')->sole()->getExtraProperty('via'));
    }

    public function test_without_radius_neither_assignment_nor_approval_creates_an_account(): void
    {
        $this->disableRadius();
        $technician = $this->technician();
        $workOrder = $this->spk();

        $this->assign($workOrder, $technician);
        $this->assertNull($this->account(), 'a secret no RADIUS server knows would mislead the technician');

        $creator = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $workOrder->fresh()->forceFill(['status' => 'waiting_review', 'submitted_at' => now(), 'created_by' => $creator->id])->saveQuietly();
        $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $technician->id])
            ->toMediaCollection('evidence', 'public');
        SpkService::approve($workOrder->fresh());

        $this->assertNull($this->account());
        $this->assertSame(0, NetworkAccount::query()->count());
        $this->assertSame(0, $this->auditCount('created'));
        $this->assertSame(0, $this->auditCount('auto_create_skipped'), 'nothing was refused: nothing was tried');
    }

    public function test_username_collision_gets_suffix(): void
    {
        $other = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company));
        $this->networkAccountFor($other, ['username' => $this->username()]);
        $taken = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company));
        $this->networkAccountFor($taken, ['username' => $this->username(suffix: '-2')]);

        $this->assign($this->spk(), $this->technician());

        $this->assertSame($this->username(suffix: '-3'), $this->account()->username);
    }

    public function test_a_customer_code_is_cleaned_and_cut_to_a_login_that_fits(): void
    {
        $this->subscription->customer->forceFill(['code' => 'Cust Ömer/2026#'.str_repeat('x', 60)])->save();

        $this->assign($this->spk(), $this->technician());

        $local = strstr($this->account()->username, '@', true);
        $this->assertMatchesRegularExpression(NetworkAccountService::LOCAL_PART_PATTERN, $local);
        $this->assertStringStartsWith('custmer2026xxxx', $local);
        $this->assertLessThanOrEqual(64, strlen($this->account()->username));
    }

    public function test_a_long_realm_shortens_the_login_to_fit_the_column(): void
    {
        $this->company->forceFill(['code' => 'ABCDEFGHIJKLMNOPQRSTUVWX'])->save();
        $this->subscription->customer->forceFill(['code' => str_repeat('a', 50)])->save();

        $this->assign($this->spk(), $this->technician());

        $username = $this->account()->username;
        $this->assertSame(64, strlen($username), '39 characters of the code, the @ and the 24 of the realm: what RADIUS and the column hold');
        $this->assertStringEndsWith('@abcdefghijklmnopqrstuvwx', $username);
    }

    public function test_resync_keeps_the_first_moment_a_disconnect_was_owed(): void
    {
        Queue::fake([ApplyNetworkAccountJob::class]);
        $account = $this->networkAccountFor($this->subscription);

        NetworkAccountService::resync($account);
        $first = $account->fresh()->pending_disconnect_at;
        $this->assertNotNull($first);

        $this->travel(10)->minutes();
        NetworkAccountService::resync($account->fresh());

        $this->assertEquals($first, $account->fresh()->pending_disconnect_at, 'a retry does not move the "since" of a Disconnect nobody answered');
        Queue::assertPushed(ApplyNetworkAccountJob::class, 2);
    }

    public function test_guard_refusal_does_not_block_assignment(): void
    {
        $nameless = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRadiusProfile($this->company, null));
        $workOrder = $this->spk(subscription: $nameless);
        $technician = $this->technician();

        $assigned = $this->assign($workOrder, $technician);

        $this->assertSame(['assigned', $technician->id], [$assigned->status, $assigned->assigned_to]);
        $this->assertNull($this->account($nameless));
        $skipped = Activity::query()->where('log_name', 'network_account')->where('description', 'auto_create_skipped')->sole();
        $this->assertSame($workOrder->id, $skipped->getExtraProperty('work_order_id'));
        $this->assertSame('Profil kecepatan paket ini belum punya nama profil RADIUS.', $skipped->getExtraProperty('reason'));
    }

    public function test_only_an_installation_spk_gets_an_account(): void
    {
        $this->assign($this->spk('maintenance'), $this->technician());
        $this->assign($this->spk('relocation'), $this->technician());

        $this->assertNull($this->account());
    }

    public function test_a_terminated_subscription_gets_no_account(): void
    {
        $workOrder = $this->spk();
        SubscriptionService::terminate($this->subscription, 'Pelanggan batal');

        $this->assign($workOrder, $this->technician());

        $this->assertNull($this->account());
        $this->assertSame(0, $this->auditCount('auto_create_skipped'), 'nothing to create is not a refusal');
    }

    public function test_cancelling_the_installation_removes_the_radius_user_and_a_new_assignment_brings_it_back(): void
    {
        $workOrder = $this->spk();
        $this->assign($workOrder, $this->technician());
        $account = $this->account();
        $secret = $account->password;
        $this->seedRadiusSession(['username' => $account->username, 'nasipaddress' => (string) $this->nas->nasname]);
        $this->assertSame([$account->username], $this->radiusUsers());

        SpkService::cancel($workOrder, 'Pelanggan membatalkan pemasangan');
        $this->sync();

        $this->assertSame([], $this->radiusUsers(), 'no enabled RADIUS user is left for an installation nobody will make');
        $this->assertSame([], $this->radiusRows('radusergroup'));
        $this->assertNull($account->fresh()->provisioned_group);
        $this->assertSame('absent', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
        $this->assertSame([$account->username], array_column(array_column($this->disconnectsSent(), 'attributes'), 'User-Name'), 'the open session is cut as well');
        $this->assertNotSame($secret, $account->fresh()->password, 'the login the technician saw is dead for good');
        $this->assertSame(1, $this->auditCount('disabled'));

        // A new installation SPK for the same subscription: the account is served again, under the secret nobody has seen.
        $again = $this->spk();
        $this->assign($again, $this->technician());

        $this->assertSame(1, NetworkAccount::query()->count());
        $this->assertSame([$account->username], $this->radiusUsers());
        $this->assertSame($account->fresh()->password, $this->radiusRows('radcheck')[0]['value']);
        $this->assertSame('enabled', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
    }

    public function test_cancelling_while_radius_is_not_configured_leaves_nothing_to_write_when_it_is(): void
    {
        $this->disableRadius();
        // Without RADIUS the assignment makes no account (K18), so this is the account staff made by hand.
        $this->networkAccountFor($this->subscription, ['username' => $this->username()]);
        $workOrder = $this->spk();
        $this->assign($workOrder, $this->technician());
        SpkService::cancel($workOrder, 'Pelanggan membatalkan pemasangan');

        $this->radiusIntegration($this->company);
        $this->sync();

        $this->assertSame([], $this->radiusUsers());
        $this->assertSame('synced', $this->account()->sync_status);
    }

    public function test_the_sweep_removes_a_user_whose_installation_was_cancelled_behind_its_back(): void
    {
        $workOrder = $this->spk();
        $this->assign($workOrder, $this->technician());
        $this->assertCount(1, $this->radiusUsers());

        // No cancel hook ran and no job was queued: only the sweep can see it.
        $workOrder->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->sync();

        $this->assertSame([], $this->radiusUsers());
    }

    public function test_terminating_the_pending_subscription_removes_the_radius_user(): void
    {
        $this->assign($this->spk(), $this->technician());
        $this->assertCount(1, $this->radiusUsers());

        SubscriptionService::terminate($this->subscription, 'Pelanggan batal');

        $this->assertSame([], $this->radiusUsers());
        $this->assertNull($this->account()->fresh()->provisioned_group);
    }

    public function test_deleting_the_cancelled_spk_does_not_bring_the_login_back(): void
    {
        $workOrder = $this->spk();
        $this->assign($workOrder, $this->technician());
        SpkService::cancel($workOrder, 'Pelanggan membatalkan pemasangan');
        $this->assertSame([], $this->radiusUsers());

        SpkService::destroy($workOrder->fresh());
        $this->sync();

        $this->assertSame([], $this->radiusUsers(), 'housekeeping is not a new installation');
        $this->assertSame('absent', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
    }

    public function test_every_status_that_means_somebody_is_installing_keeps_the_account_served(): void
    {
        $technician = $this->technician();
        $this->spk(status: 'cancelled', attributes: ['assigned_to' => $technician->id]);
        $this->assertTrue(NetworkAccountService::installationAbandoned($this->subscription), 'nothing is underway after the cancel');

        foreach (['assigned', 'in_progress', 'waiting_review', 'rejected', 'completed'] as $status) {
            $other = $this->spk(status: $status, attributes: ['assigned_to' => $technician->id]);
            $this->assertFalse(NetworkAccountService::installationAbandoned($this->subscription), $status);
            $other->forceDelete();
        }

        foreach (['draft', 'generated'] as $status) {
            $plan = $this->spk(status: $status);
            $this->assertTrue(NetworkAccountService::installationAbandoned($this->subscription), "a plan ({$status}) is no installation underway");
            $plan->forceDelete();
        }
    }

    public function test_an_installation_that_is_still_underway_keeps_the_account_when_another_one_is_cancelled(): void
    {
        $underway = $this->spk();
        $this->assign($underway, $this->technician());
        $doomed = $this->spk();
        $this->assign($doomed, $this->technician());

        SpkService::cancel($doomed, 'Dobel');

        $this->assertSame([$this->username()], $this->radiusUsers());
        $this->assertSame('enabled', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
        $this->assertSame(0, $this->auditCount('disabled'));
    }

    public function test_cancelling_an_spk_nobody_was_assigned_leaves_a_manual_account_alone(): void
    {
        $manual = $this->networkAccountFor($this->subscription, ['username' => $this->username(suffix: '.manual')]);
        $this->applyAccount($manual);
        $draft = $this->spk(status: 'draft');

        SpkService::cancel($draft, 'Salah buat');

        $this->assertSame([$manual->username], $this->radiusUsers());
        $this->assertSame('enabled', app(NetworkAccountProvisioner::class)->stateOf($this->subscription->fresh()));
    }

    public function test_an_active_subscription_keeps_its_account_when_an_installation_spk_is_cancelled(): void
    {
        $active = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company));
        $workOrder = $this->spk(subscription: $active);
        $this->assign($workOrder, $this->technician());
        $account = $this->account($active);
        $secret = $account->password;

        SpkService::cancel($workOrder, 'Batal');

        $this->assertSame([$account->username], $this->radiusUsers());
        $this->assertSame($secret, $account->fresh()->password, 'a customer who is online is not disturbed');
        $this->assertSame(0, $this->auditCount('disabled'));
    }
}
