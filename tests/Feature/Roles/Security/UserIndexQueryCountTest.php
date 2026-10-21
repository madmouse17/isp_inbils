<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;

/**
 * House rule 12 (no N+1) on the Users index. Every row asks the user policy three things (delete, reset two-factor,
 * assign roles), and a user who belongs to several companies is judged in each of them (H1): the number of queries of
 * the page must not grow with the number of rows, however many companies the members belong to.
 */
class UserIndexQueryCountTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildGroup();
    }

    /**
     * One member homed in $home, also a member of each company of $others, holding the template role of each.
     *
     * @param  array<int, array{0: Company, 1: string|null}>  $others  company => template key (null: no role there)
     */
    private function member(Company $home, string $homeRole, array $others, bool $twoFactor = false): User
    {
        $user = $this->groupUser($home, $homeRole);

        foreach ($others as [$company, $role]) {
            app(CompanyMembershipService::class)->grant($user, $company);

            if ($role !== null) {
                $this->grantRole($user, $role, $company);
            }
        }

        if ($twoFactor) {
            $this->enrollTwoFactor($user);
        }

        return $user;
    }

    /** One of every kind of row the page has: the members of two and of three companies, with and without a second factor. */
    private function addRows(int $sets): void
    {
        for ($i = 0; $i < $sets; $i++) {
            $this->member($this->head, 'staff', [[$this->subA, 'technician']]);
            $this->member($this->head, 'manager', [[$this->subA, 'staff'], [$this->subB, 'manager']], twoFactor: true);
            $this->member($this->head, 'technician', [[$this->subB, 'staff']], twoFactor: true);
            $this->member($this->head, 'staff', []);
            $this->member($this->subA, 'staff', [[$this->head, 'technician'], [$this->subB, null]]);
            $this->member($this->head, 'finance', [[$this->subA, null]]);
        }
    }

    /**
     * The statements of one cold render of the index (a fresh actor, a warm permission cache), as [statement, ...].
     *
     * @return list<string>
     */
    private function indexQueries(User $actor): array
    {
        $url = route('admin.users.index', ['per_page' => 50]);
        $again = fn (): User => User::query()->findOrFail($actor->id);

        // One request first: the permission cache and the container fill once, whatever the page holds.
        $this->actingAs($again())->get($url)->assertOk();
        app(PermissionRegistrar::class)->getPermissions();

        $this->actingAs($again());
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->get($url)->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        return $queries;
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_rows(): void
    {
        $actor = $this->member($this->head, 'admin', [[$this->subA, 'admin'], [$this->subB, 'admin']]);

        $this->addRows(1);
        $few = $this->indexQueries($actor);

        $this->addRows(4);
        $many = $this->indexQueries($actor);

        // The rows are on the page (the page is not an empty one that costs nothing), and the policy answers.
        $this->actingAs(User::query()->findOrFail($actor->id))->get(route('admin.users.index', ['per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 31)
                ->where('users.data', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['can']['delete'] === true && $row['can']['reset_two_factor'] === true)
                    && collect($rows)->contains(fn (array $row): bool => $row['is_member_only'] === true && $row['can']['assign_roles'] === true)));

        $this->assertSame(
            count($few),
            count($many),
            sprintf("queries for 7 rows: %d, for 31 rows: %d\n%s", count($few), count($many), $this->repeated($many)),
        );
    }

    public function test_a_page_of_members_of_more_companies_costs_the_same_number_of_queries(): void
    {
        $actor = $this->member($this->head, 'admin', [[$this->subA, 'admin'], [$this->subB, 'admin']]);

        // Every member belongs to the home company and one subsidiary...
        for ($i = 0; $i < 6; $i++) {
            $this->member($this->head, 'staff', [[$this->subA, 'staff']], twoFactor: true);
        }
        $two = $this->indexQueries($actor);

        // ...then each of them also belongs to the other one, with a role of his own there.
        User::query()->where('company_id', $this->head->id)->where('id', '<>', $actor->id)->get()->each(function (User $user): void {
            app(CompanyMembershipService::class)->grant($user, $this->subB);
            $this->grantRole($user, 'technician', $this->subB);
        });
        $three = $this->indexQueries($actor);

        $this->assertSame(
            count($two),
            count($three),
            sprintf("queries with two companies each: %d, with three: %d\n%s", count($two), count($three), $this->repeated($three)),
        );
    }

    /** The statements that ran more than once, most repeated first: where a per-row query shows. */
    private function repeated(array $queries): string
    {
        $counts = array_count_values(array_map(fn (string $sql): string => preg_replace('/\s+/', ' ', $sql), $queries));
        arsort($counts);

        return collect($counts)->filter(fn (int $n): bool => $n > 1)->map(fn (int $n, string $sql): string => "{$n}x ".mb_substr($sql, 0, 220))->take(12)->implode("\n");
    }
}
