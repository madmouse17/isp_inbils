<?php

namespace Tests\Feature\Platform;

use App\Jobs\Platform\SeedTenantDemoDataJob;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The queued side of sample data: where the job goes, that it is unique per company and single-try, what it records
 * in the platform audit, and that the HTTP request that provisions a tenant never runs the seeder itself.
 */
class DemoDataJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_goes_to_the_platform_queue_on_the_platform_connection_with_one_hour_and_one_try(): void
    {
        Queue::fake();

        SeedTenantDemoDataJob::dispatch(7, 11, 3)->afterCommit();

        Queue::assertPushed(SeedTenantDemoDataJob::class, function (SeedTenantDemoDataJob $job): bool {
            $this->assertSame('platform', $job->queue);
            $this->assertSame('platform', $job->connection);
            $this->assertSame([1, 3600, true], [$job->tries, $job->timeout, $job->failOnTimeout]);
            $this->assertSame([7, 11, 3], [$job->companyId, $job->adminUserId, $job->platformAdminId]);
            $this->assertSame('7', $job->uniqueId());

            return true;
        });
    }

    public function test_it_is_unique_per_company(): void
    {
        Queue::fake();

        SeedTenantDemoDataJob::dispatch(7, 11, 3);
        SeedTenantDemoDataJob::dispatch(7, 12, 3);
        SeedTenantDemoDataJob::dispatch(8, 13, 3);

        Queue::assertPushed(SeedTenantDemoDataJob::class, 2);
        Queue::assertPushed(SeedTenantDemoDataJob::class, fn (SeedTenantDemoDataJob $job): bool => $job->companyId === 7 && $job->adminUserId === 11);
        Queue::assertPushed(SeedTenantDemoDataJob::class, fn (SeedTenantDemoDataJob $job): bool => $job->companyId === 8);
    }

    public function test_the_platform_connection_has_a_retry_after_above_the_job_timeout(): void
    {
        $platform = config('queue.connections.platform');
        $database = config('queue.connections.database');

        $this->assertSame('database', $platform['driver']);
        $this->assertSame('platform', $platform['queue']);
        $this->assertSame($database['table'], $platform['table'], 'the same jobs table as the default connection');
        $this->assertSame($database['connection'], $platform['connection']);
        $this->assertSame(3660, $platform['retry_after']);
        $this->assertGreaterThan((new SeedTenantDemoDataJob(1, 1, 1))->timeout, $platform['retry_after']);
        $this->assertFalse($platform['after_commit']);
    }

    public function test_a_run_seeds_that_company_for_that_admin_and_audits_it_under_the_platform_admin(): void
    {
        [$company, $admin, $operator] = $this->tenantAndOperator();

        $this->mock(DemoDataSeeder::class, function (MockInterface $seeder) use ($company, $admin): void {
            $seeder->shouldReceive('seedFor')->once()->withArgs(fn (Company $c, User $a): bool => $c->is($company) && $a->is($admin));
        });

        SeedTenantDemoDataJob::dispatchSync($company->id, $admin->id, $operator->id);

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.demo_data_seeded')->sole();
        $this->assertSame($company->id, (int) $row->company_id);
        $this->assertSame([PlatformAdmin::class, $operator->id], [$row->causer_type, (int) $row->causer_id]);
        $this->assertSame('TNB', $row->properties['tenant_code']);
    }

    public function test_a_failed_run_is_audited_with_the_error_class_and_a_short_message_and_no_trace(): void
    {
        [$company, , $operator] = $this->tenantAndOperator();
        $job = new SeedTenantDemoDataJob($company->id, 1, $operator->id);

        $job->failed(new RuntimeException(str_repeat('boom ', 200)));

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.demo_data_failed')->sole();
        $this->assertSame($company->id, (int) $row->company_id);
        $this->assertSame([PlatformAdmin::class, $operator->id], [$row->causer_type, (int) $row->causer_id]);
        $this->assertSame('RuntimeException', $row->properties['error']);
        $this->assertLessThanOrEqual(300, mb_strlen($row->properties['message']));
        $this->assertStringNotContainsString('#0', json_encode($row->properties, JSON_THROW_ON_ERROR), 'no stack trace');
    }

    public function test_a_failure_is_still_audited_when_the_company_or_the_operator_is_gone(): void
    {
        (new SeedTenantDemoDataJob(999999, 1, 888888))->failed(new RuntimeException('late'));

        $row = Activity::query()->where('description', 'tenant.demo_data_failed')->sole();
        $this->assertNull($row->company_id);
        $this->assertNull($row->causer_id);
        $this->assertSame(999999, $row->properties['tenant_id']);
    }

    /** @return array{0: Company, 1: User, 2: PlatformAdmin} */
    private function tenantAndOperator(): array
    {
        $company = Company::factory()->create(['code' => 'TNB']);

        return [$company, User::factory()->create(['company_id' => $company->id]), PlatformAdmin::factory()->create()];
    }
}
