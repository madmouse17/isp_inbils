<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L6: the permission cache must not outlive a role change. (a) A role write inside an outer transaction dropped the
 * cache before that transaction committed, so a request in between could rebuild it from the old rows; (b) a queue
 * worker kept the package's in-memory permission map for the life of its process, so a permission taken away from a role
 * still passed in a job.
 */
class PermissionCacheFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_write_inside_an_outer_transaction_drops_the_cache_again_when_that_transaction_commits(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $key = config('permission.cache.key');

        DB::transaction(function () use ($company, $key): void {
            app(CompanyRoleService::class)->transaction((int) $company->id, fn () => null);

            // A request that checks a permission now rebuilds the cache from rows the outer transaction has not committed.
            app(PermissionRegistrar::class)->getPermissions();
            $this->assertTrue(Cache::has($key));
        });

        $this->assertFalse(Cache::has($key), 'the rebuild of the old state does not survive the commit');
    }

    public function test_a_job_starts_from_the_shared_cache_not_from_the_map_its_worker_loaded_earlier(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $role = $this->companyRole($company, 'Kasir', ['billing.view']);
        $user = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($user, $role, $company);
        $can = fn (): bool => CompanyService::runAs((int) $company->id, fn (): bool => $user->can('billing.view'));

        $this->assertTrue($can(), 'this worker has loaded the permission map');

        // Another process takes the permission away and drops the shared cache; this one never hears of it.
        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        Cache::forget(config('permission.cache.key'));
        $this->assertTrue($can(), 'the long-lived worker still answers from memory');

        $job = $this->createMock(Job::class);
        $job->method('payload')->willReturn([]);
        event(new JobProcessing('redis', $job));

        $this->assertFalse($can(), 'the next job asks the shared cache again');
    }
}
