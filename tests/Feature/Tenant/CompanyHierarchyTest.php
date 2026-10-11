<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

class CompanyHierarchyTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    // ---------------------------------------------------------------------------------------------
    // Hierarchy: one level, no cycles
    // ---------------------------------------------------------------------------------------------

    public function test_a_head_is_a_company_without_a_parent_that_has_subsidiaries(): void
    {
        $this->assertTrue($this->head->isHead());
        $this->assertFalse($this->subA->isHead());
        $this->assertFalse($this->outsider->isHead());
        $this->assertSame($this->head->id, $this->subA->parent->id);
        $this->assertEqualsCanonicalizing([$this->subA->id, $this->subB->id], $this->head->children->modelKeys());
    }

    public function test_a_company_with_subsidiaries_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        DB::table('companies')->where('id', $this->head->id)->delete();
    }

    public function test_set_parent_attaches_and_detaches_a_standalone_company(): void
    {
        $service = app(CompanyHierarchyService::class);

        $service->setParent($this->outsider, $this->head);
        $this->assertSame($this->head->id, $this->outsider->fresh()->parent_company_id);

        $service->setParent($this->outsider, null);
        $this->assertNull($this->outsider->fresh()->parent_company_id);
    }

    public function test_set_parent_rejects_self_cycles_and_a_third_level(): void
    {
        $service = app(CompanyHierarchyService::class);

        $this->assertRejected(fn () => $service->setParent($this->outsider, $this->outsider), 'itself');
        // Head -> A -> Head would be a cycle.
        $this->assertRejected(fn () => $service->setParent($this->head, $this->subA), 'subsidiary');
        // A is already a subsidiary: a company below it would be a third level.
        $this->assertRejected(fn () => $service->setParent($this->outsider, $this->subA), 'subsidiary');
        // A company that has subsidiaries cannot itself become a subsidiary.
        $this->assertRejected(fn () => $service->setParent($this->head, $this->outsider), 'subsidiaries');

        $this->assertNull($this->head->fresh()->parent_company_id);
        $this->assertNull($this->outsider->fresh()->parent_company_id);
    }

    public function test_the_consolidation_set_is_the_head_and_its_active_subsidiaries_only(): void
    {
        $inactive = $this->subsidiary('Dormant', 'DOR');
        $inactive->forceFill(['is_active' => false])->save();

        $set = app(CompanyHierarchyService::class)->consolidationSet($this->head);

        $this->assertSame($this->head->id, $set->first()->id);
        $this->assertEqualsCanonicalizing(
            [$this->head->id, $this->subA->id, $this->subB->id],
            $set->modelKeys(),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Membership invariants
    // ---------------------------------------------------------------------------------------------

    public function test_granting_membership_is_idempotent_and_keeps_one_default_per_user(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $service = app(CompanyMembershipService::class);

        $service->grant($user, $this->subA);
        $service->grant($user, $this->head);

        $rows = DB::table('company_user')->where('user_id', $user->id)->orderBy('company_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->head->id], $rows->where('is_default', true)->pluck('company_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_database_allows_a_single_default_membership_per_user(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->expectException(QueryException::class);

        DB::table('company_user')
            ->where('user_id', $user->id)
            ->where('company_id', $this->subA->id)
            ->update(['is_default' => true]);
    }

    public function test_the_default_membership_must_be_the_home_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->head->id]);

        $this->assertRejected(fn () => app(CompanyMembershipService::class)->grant($user, $this->subA, true), 'home');
    }

    public function test_a_user_without_a_home_company_gets_the_default_membership_as_home(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        app(CompanyMembershipService::class)->grant($user, $this->head, true);

        $this->assertSame($this->head->id, $user->fresh()->company_id);
        $this->assertTrue((bool) DB::table('company_user')->where('user_id', $user->id)->value('is_default'));
    }

    public function test_revoking_removes_the_row_and_is_audited_but_the_home_company_stays(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $service = app(CompanyMembershipService::class);

        $service->revoke($user, $this->subA);

        $this->assertFalse(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $this->subA->id)->exists());
        $this->assertSame(1, Activity::query()->where('description', 'membership_revoked')->count());
        $this->assertRejected(fn () => $service->revoke($user, $this->head), 'home');
        $this->assertTrue(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $this->head->id)->exists());
    }

    public function test_granting_is_audited(): void
    {
        $user = User::factory()->create(['company_id' => $this->head->id]);

        app(CompanyMembershipService::class)->grant($user, $this->subA);

        $entry = Activity::query()->where('description', 'membership_granted')->sole();
        $this->assertSame($this->subA->id, (int) $entry->company_id);
        $this->assertSame($user->id, $entry->properties['user_id']);
    }

    // ---------------------------------------------------------------------------------------------
    // Artisan: create-subsidiary and membership commands
    // ---------------------------------------------------------------------------------------------

    public function test_create_subsidiary_command_creates_a_company_under_the_head_with_its_defaults(): void
    {
        $member = User::factory()->create(['company_id' => $this->head->id, 'email' => 'ops@group.test']);

        $this->artisan('inbils:company:create-subsidiary', [
            'head' => 'HDQ',
            'name' => 'Sub Charlie',
            'code' => 'SBC',
            '--member' => ['ops@group.test'],
        ])->expectsOutputToContain('Sub Charlie')->assertExitCode(0);

        $created = Company::query()->where('code', 'SBC')->sole();
        $this->assertSame($this->head->id, $created->parent_company_id);
        $this->assertSame('IDR', $created->currency);
        $this->assertSame('Asia/Jakarta', $created->timezone);
        $this->assertTrue($created->is_active);
        $this->assertTrue(DB::table('company_user')->where('user_id', $member->id)->where('company_id', $created->id)->exists());
        $this->assertGreaterThan(0, DB::table('units')->where('company_id', $created->id)->count(), 'company defaults were not seeded');
    }

    public function test_create_subsidiary_command_accepts_the_head_by_id_and_a_currency_override(): void
    {
        $this->artisan('inbils:company:create-subsidiary', [
            'head' => (string) $this->head->id,
            'name' => 'Sub Dollar',
            'code' => 'SBD',
            '--currency' => 'USD',
            '--timezone' => 'Asia/Singapore',
        ])->assertExitCode(0);

        $created = Company::query()->where('code', 'SBD')->sole();
        $this->assertSame('USD', $created->currency);
        $this->assertSame('Asia/Singapore', $created->timezone);
    }

    public function test_create_subsidiary_command_refuses_bad_input_and_creates_nothing(): void
    {
        $before = Company::query()->count();

        // unknown head
        $this->artisan('inbils:company:create-subsidiary', ['head' => 'NOPE', 'name' => 'X', 'code' => 'XXX'])->assertExitCode(1);
        // a subsidiary cannot be a head (third level)
        $this->artisan('inbils:company:create-subsidiary', ['head' => 'SBA', 'name' => 'X', 'code' => 'XXX'])->assertExitCode(1);
        // duplicate code
        $this->artisan('inbils:company:create-subsidiary', ['head' => 'HDQ', 'name' => 'X', 'code' => 'SBA'])->assertExitCode(1);
        // unknown member email: refused before anything is created
        $this->artisan('inbils:company:create-subsidiary', ['head' => 'HDQ', 'name' => 'X', 'code' => 'XXX', '--member' => ['ghost@group.test']])->assertExitCode(1);

        $this->assertSame($before, Company::query()->count());
    }

    public function test_grant_and_revoke_membership_commands(): void
    {
        $user = User::factory()->create(['company_id' => $this->head->id, 'email' => 'ops@group.test']);

        $this->artisan('inbils:company:grant-membership', ['user' => 'ops@group.test', 'company' => 'SBA'])->assertExitCode(0);
        $this->assertTrue(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $this->subA->id)->exists());

        $this->artisan('inbils:company:revoke-membership', ['user' => 'ops@group.test', 'company' => (string) $this->subA->id])->assertExitCode(0);
        $this->assertFalse(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $this->subA->id)->exists());

        $this->artisan('inbils:company:grant-membership', ['user' => 'nobody@group.test', 'company' => 'SBA'])->assertExitCode(1);
        $this->artisan('inbils:company:grant-membership', ['user' => 'ops@group.test', 'company' => 'NOPE'])->assertExitCode(1);
        $this->artisan('inbils:company:revoke-membership', ['user' => 'ops@group.test', 'company' => 'HDQ'])->assertExitCode(1);
    }

    private function assertRejected(callable $action, string $messagePart): void
    {
        try {
            $action();
            $this->fail('Expected the operation to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsStringIgnoringCase($messagePart, $e->getMessage());
        }
    }
}
