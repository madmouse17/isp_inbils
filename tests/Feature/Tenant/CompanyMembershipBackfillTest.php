<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyMembershipBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_11_000003_backfill_default_company_memberships.php';

    public function test_up_gives_every_user_with_a_home_company_one_default_membership(): void
    {
        [$a, $b] = [Company::factory()->create(), Company::factory()->create()];
        $inA = User::factory()->create(['company_id' => $a->id]);
        $inB = User::factory()->create(['company_id' => $b->id]);
        $bootstrap = User::factory()->create(['company_id' => null]);

        $this->migration()->up();

        $this->assertSame([[$a->id, 1]], $this->membershipsOf($inA));
        $this->assertSame([[$b->id, 1]], $this->membershipsOf($inB));
        $this->assertSame([], $this->membershipsOf($bootstrap));
    }

    public function test_up_can_run_twice_and_keeps_existing_extra_memberships(): void
    {
        [$a, $b] = [Company::factory()->create(), Company::factory()->create()];
        $user = User::factory()->create(['company_id' => $a->id]);
        DB::table('company_user')->insert(['company_id' => $b->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame([[$a->id, 1], [$b->id, 0]], $this->membershipsOf($user));
    }

    public function test_down_removes_only_the_backfilled_home_memberships(): void
    {
        [$a, $b] = [Company::factory()->create(), Company::factory()->create()];
        $user = User::factory()->create(['company_id' => $a->id]);
        DB::table('company_user')->insert(['company_id' => $b->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame([[$b->id, 0]], $this->membershipsOf($user));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    /** @return list<array{0: int, 1: int}> */
    private function membershipsOf(User $user): array
    {
        return DB::table('company_user')->where('user_id', $user->id)->orderBy('company_id')->get()
            ->map(fn (object $row): array => [(int) $row->company_id, (int) $row->is_default])
            ->all();
    }
}
