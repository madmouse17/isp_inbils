<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * L4: two administrators who delete each other must not both succeed. The deletion reads before it locks the
 * companies, and under REPEATABLE READ the first read of a transaction fixes its snapshot: the second deletion then
 * judged the company by a snapshot taken before the first one committed. The company locks are now the first statements
 * of the transaction that deletes, and the profile page deletes inside that same transaction.
 */
class LastAdministratorLockTest extends TestCase
{
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    /** Each worker deletes the user it is given, the way the user screen does. */
    private const DELETE = <<<'PHP'
        config(['logging.default' => 'null']);

        App\Support\UserDeletionGuard::delete(App\Models\User::query()->findOrFail($args['user_id']));

        return 'deleted';
    PHP;

    private function administrator(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        app(CompanyMembershipService::class)->grant($user, $company, true);

        return $this->grantRole($user, 'admin', $company);
    }

    public function test_two_administrators_deleting_each_other_at_once_leave_one(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $x = $this->administrator($company);
        $y = $this->administrator($company);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'user_id' => $y->id],
                ['company_id' => $company->id, 'user_id' => $x->id],
            ], self::DELETE);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(['ok' => false, 'status' => 422, 'errors' => ['user' => [CompanyRoleService::LAST_ADMINISTRATOR]]], $losers[0]);

            $remaining = User::query()->whereIn('id', [$x->id, $y->id])->pluck('id')->all();
            $this->assertCount(1, $remaining, 'one of the two administrators is left');
            $this->assertTrue(app(CompanyRoleService::class)->isAdministrator(User::query()->findOrFail($remaining[0]), $company->id));
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_the_user_screen_locks_the_companies_before_it_reads_anything_in_the_deleting_transaction(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $admin = $this->administrator($company);
        $victim = $this->grantRole(User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]), 'staff', $company);
        app(CompanyMembershipService::class)->grant($victim, $company, true);
        $this->withoutVite();

        $queries = $this->recordQueries(fn () => $this->actingAs($admin)->delete(route('admin.users.destroy', $victim))->assertRedirect());

        // The test itself runs inside a wrapping transaction (level 1): the deleting one is the next level.
        $inside = array_values(array_filter($queries, fn (array $query): bool => $query['level'] >= 2));
        $this->assertNotSame([], $inside);
        $this->assertStringContainsString('from `companies`', $inside[0]['sql']);
        $this->assertStringContainsString('for update', $inside[0]['sql']);
        $this->assertSame(1, count(array_filter($queries, fn (array $query): bool => str_starts_with($query['sql'], 'delete from `users`') && $query['level'] >= 2)));
        $this->assertFalse(User::query()->whereKey($victim->id)->exists());
    }

    public function test_the_profile_page_checks_and_deletes_in_one_transaction(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $this->administrator($company);
        $leaving = $this->administrator($company);
        $this->withoutVite();

        $queries = $this->recordQueries(fn () => $this->actingAs($leaving)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/'));

        $inside = array_values(array_filter($queries, fn (array $query): bool => $query['level'] >= 2));
        $this->assertStringContainsString('for update', $inside[0]['sql']);
        $delete = array_values(array_filter($queries, fn (array $query): bool => str_starts_with($query['sql'], 'delete from `users`')));
        $this->assertCount(1, $delete);
        $this->assertGreaterThanOrEqual(2, $delete[0]['level'], 'the delete runs in the transaction that holds the company locks');
        $this->assertFalse(User::query()->whereKey($leaving->id)->exists());
        $this->assertGuest();
    }

    /**
     * Every statement the callback runs, with the transaction level it ran at.
     *
     * @return list<array{level: int, sql: string}>
     */
    private function recordQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['level' => $query->connection->transactionLevel(), 'sql' => $query->sql];
        });

        $callback();

        return $queries;
    }
}
