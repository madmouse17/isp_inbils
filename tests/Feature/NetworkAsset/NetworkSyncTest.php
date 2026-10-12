<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

class NetworkSyncTest extends TestCase
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

    private NetworkAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->realm = $this->realmOf($this->company);
        $this->fakeRadius();
        $this->nas = $this->nasClient($this->company);
        $this->coaAnswers((string) $this->nas->nasname, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription);
    }

    private function sync(?Company $company = null): void
    {
        $this->artisan('network:sync', ['--company' => ($company ?? $this->company)->id])->assertSuccessful();
    }

    private function auditCount(string $log, string $event): int
    {
        return Activity::query()->where('log_name', $log)->where('description', $event)->count();
    }

    public function test_an_unsynced_nas_is_written_as_a_radius_client_with_the_company_realm(): void
    {
        $this->sync();

        $rows = $this->radiusRows('nas', ['nasname' => $this->nas->nasname]);
        $this->assertCount(1, $rows);
        $this->assertSame($this->realm, $rows[0]['shortname']);
        $this->assertSame('other', $rows[0]['type']);
        $this->assertSame('fake-radius-shared-secret', $rows[0]['secret']);
        $this->assertStringContainsString($this->nas->networkAsset->code, $rows[0]['description']);
        $nas = $this->nas->fresh();
        $this->assertNotNull($nas->radius_synced_at);
        $this->assertSame($nas->nasname, $nas->radius_nasname);
        $this->assertNull($nas->last_error);
        $this->assertSame(1, $this->auditCount('nas_device', 'radius_written'));
    }

    public function test_a_nas_that_is_in_sync_is_not_written_again_and_foreign_nas_rows_are_never_touched(): void
    {
        $this->radiusConnection()->table('nas')->insert(['nasname' => '127.0.0.1', 'shortname' => 'lab', 'type' => 'other', 'secret' => 'lab-secret']);
        $this->sync();
        $this->radiusConnection()->table('nas')->where('nasname', $this->nas->nasname)->update(['shortname' => 'edited']);

        $this->sync();

        $this->assertSame('edited', $this->radiusRows('nas', ['nasname' => $this->nas->nasname])[0]['shortname']);
        $lab = $this->radiusRows('nas', ['nasname' => '127.0.0.1'])[0];
        $this->assertSame(['lab', 'lab-secret'], [$lab['shortname'], $lab['secret']]);
        $this->assertSame(1, $this->auditCount('nas_device', 'radius_written'));
    }

    public function test_a_nas_row_inbils_did_not_write_is_refused_and_recorded(): void
    {
        $this->radiusConnection()->table('nas')->insert(['nasname' => $this->nas->nasname, 'shortname' => 'ops', 'type' => 'other', 'secret' => 'ops-secret']);

        $this->sync();

        $row = $this->radiusRows('nas', ['nasname' => $this->nas->nasname])[0];
        $this->assertSame(['ops', 'ops-secret'], [$row['shortname'], $row['secret']]);
        $nas = $this->nas->fresh();
        $this->assertNull($nas->radius_synced_at);
        $this->assertNotNull($nas->last_error);
        $this->assertSame(1, $this->auditCount('nas_device', 'radius_failed'));
    }

    public function test_a_changed_nasname_writes_the_new_row_and_removes_the_previous_one(): void
    {
        $this->sync();
        $old = (string) $this->nas->nasname;

        $this->nas->forceFill(['nasname' => '198.51.100.77'])->save();
        $this->sync();

        $this->assertSame([], $this->radiusRows('nas', ['nasname' => $old]));
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '198.51.100.77']));
        $this->assertSame('198.51.100.77', $this->nas->fresh()->radius_nasname);
    }

    public function test_a_radius_failure_on_a_nas_is_recorded_and_the_next_sync_retries(): void
    {
        Schema::connection('radius')->drop('nas');

        $this->sync();

        $nas = $this->nas->fresh();
        $this->assertNull($nas->radius_synced_at);
        $this->assertSame('The RADIUS database is unavailable.', $nas->last_error);
        $this->assertSame(1, $this->auditCount('nas_device', 'radius_failed'));

        $this->fakeRadius();
        $this->sync();

        $this->assertNotNull($this->nas->fresh()->radius_synced_at);
        $this->assertNull($this->nas->fresh()->last_error);
    }

    public function test_a_nas_of_a_company_without_a_valid_realm_is_not_written(): void
    {
        $this->company->forceFill(['code' => 'ACME_CORP'])->save();

        $this->sync();

        $this->assertSame([], $this->radiusRows('nas'));
        $this->assertNull($this->nas->fresh()->radius_synced_at);
        $this->assertNotNull($this->nas->fresh()->last_error);
    }

    public function test_a_pending_account_is_applied_by_the_sync(): void
    {
        $this->sync();

        $this->assertSame('synced', $this->account->fresh()->sync_status);
        $this->assertCount(1, $this->radiusRows('radcheck'));
    }

    public function test_drift_is_queued_corrected_and_audited(): void
    {
        $this->applyAccount($this->account);
        $this->radiusConnection()->table('radusergroup')->update(['groupname' => 'somebody-elses']);

        $this->sync();

        $this->assertSame("{$this->realm}/home-20m", $this->radiusRows('radusergroup')[0]['groupname']);
        $this->assertSame(1, $this->auditCount('network_account', 'drift_corrected'));
    }

    public function test_a_user_missing_from_radius_is_created_again_by_the_sync(): void
    {
        $this->applyAccount($this->account);
        $this->radiusConnection()->table('radcheck')->delete();
        $this->radiusConnection()->table('radusergroup')->delete();

        $this->sync();

        $this->assertCount(1, $this->radiusRows('radcheck'));
    }

    public function test_an_account_that_matches_radius_is_not_queued_again(): void
    {
        $this->applyAccount($this->account);
        Queue::fake();

        $this->sync();

        Queue::assertNotPushed(ApplyNetworkAccountJob::class);
    }

    public function test_an_account_that_owes_a_disconnect_is_queued_again(): void
    {
        $this->applyAccount($this->account);
        $this->account->forceFill(['pending_disconnect_at' => now()])->save();
        Queue::fake();

        $this->sync();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
    }

    public function test_a_failed_account_is_requeued_at_most_once_per_hour(): void
    {
        $this->subscription->forceFill(['service_package_id' => $this->packageWithRadiusProfile($this->company, null)->id])->saveQuietly();
        $this->account->forceFill(['sync_status' => 'failed', 'last_error' => 'earlier failure', 'last_attempt_at' => now()->subMinutes(30)])->save();
        Queue::fake();

        $this->sync();
        Queue::assertNotPushed(ApplyNetworkAccountJob::class);

        $this->account->forceFill(['last_attempt_at' => now()->subMinutes(61)])->save();
        $this->sync();
        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
    }

    public function test_an_account_that_cannot_be_applied_is_not_drift(): void
    {
        $this->applyAccount($this->account);
        $this->subscription->forceFill(['service_package_id' => $this->packageWithRadiusProfile($this->company, null)->id])->saveQuietly();
        $this->account->forceFill(['sync_status' => 'failed', 'last_attempt_at' => now()])->save();
        Queue::fake();

        $this->sync();

        Queue::assertNotPushed(ApplyNetworkAccountJob::class);
    }

    public function test_a_terminated_account_still_present_in_radius_is_removed(): void
    {
        $this->applyAccount($this->account);
        $this->subscription->forceFill(['status' => 'terminated'])->saveQuietly();

        $this->sync();

        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertNull($this->account->fresh()->provisioned_group);
    }

    public function test_with_radius_down_pending_accounts_are_still_queued_and_the_command_does_not_fail(): void
    {
        Schema::connection('radius')->drop('radcheck');
        Queue::fake();

        $this->sync();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
    }

    public function test_with_the_installation_switch_off_the_sync_does_nothing_at_all(): void
    {
        config(['services.radius.enabled' => false]);
        Queue::fake();

        $this->artisan('network:sync')->expectsOutputToContain('switched off')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($this->nas->fresh()->radius_synced_at);
    }

    public function test_the_company_option_limits_the_sync_and_without_it_every_active_company_is_synced(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirNas = $this->nasClient($other);
        $theirs = $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other, 'home-20m')), ['username' => 'zed@'.$this->realmOf($other)]);
        $inactive = Company::factory()->create(['is_active' => false]);
        $inactiveNas = $this->nasClient($inactive);

        $this->sync();

        $this->assertNotNull($this->nas->fresh()->radius_synced_at);
        $this->assertNull($theirNas->fresh()->radius_synced_at, 'company B was not asked for');
        $this->assertSame('pending', $theirs->fresh()->sync_status);

        $this->artisan('network:sync')->assertSuccessful();

        $this->assertNotNull($theirNas->fresh()->radius_synced_at);
        $this->assertSame('synced', $theirs->fresh()->sync_status);
        $this->assertNull($inactiveNas->fresh()->radius_synced_at);
    }

    public function test_the_sync_is_scheduled_every_fifteen_minutes_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'network:sync'));

        $this->assertNotNull($event, 'network:sync is not scheduled.');
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
