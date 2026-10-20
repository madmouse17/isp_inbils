<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * Who hears of an event that has no single addressee: the active members of the company who hold the permission. A member
 * is a user of the home company or one with a `company_user` row; `users.company_id` alone is never the tenant.
 */
class StaffRecipientsTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private const PERMISSION = 'ticket.assign';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    /** @return list<int> */
    private function holders(int $companyId, ?int $except = null, string $permission = self::PERMISSION): array
    {
        return StaffRecipients::holding($companyId, $permission, $except)->modelKeys();
    }

    public function test_active_members_holding_the_permission_are_returned_and_the_others_are_not(): void
    {
        $dispatcher = $this->groupUser($this->subA, 'manager');
        $this->groupUser($this->subA, 'staff');

        $this->assertSame([$dispatcher->id], $this->holders($this->subA->id));
    }

    public function test_a_permission_given_to_the_user_counts_like_one_from_a_role(): void
    {
        $direct = $this->groupUser($this->subA, 'staff');
        $direct->givePermissionTo(self::PERMISSION);
        $viaRole = $this->groupUser($this->subA, 'manager');

        $this->assertEqualsCanonicalizing([$direct->id, $viaRole->id], $this->holders($this->subA->id));
    }

    public function test_a_head_user_who_is_a_member_of_the_subsidiary_receives_the_subsidiarys_notice(): void
    {
        $roaming = $this->groupUser($this->head, 'manager', $this->subA);
        $subsidiaryOnly = $this->groupUser($this->subA, 'manager');
        $headOnly = $this->groupUser($this->head, 'manager');

        $this->assertEqualsCanonicalizing([$roaming->id, $subsidiaryOnly->id], $this->holders($this->subA->id));
        $this->assertEqualsCanonicalizing([$roaming->id, $headOnly->id], $this->holders($this->head->id), 'the home company still counts');
        $this->assertSame([], $this->holders($this->subB->id), 'a membership in one subsidiary says nothing of another');
    }

    public function test_a_user_homed_in_the_company_without_a_membership_row_still_counts(): void
    {
        $legacy = User::factory()->create(['company_id' => $this->subA->id, 'is_active' => true]);
        $legacy->givePermissionTo(self::PERMISSION);
        $this->assertSame(0, DB::table('company_user')->where('user_id', $legacy->id)->count());

        $this->assertSame([$legacy->id], $this->holders($this->subA->id));
    }

    public function test_a_user_of_another_company_never_receives_it(): void
    {
        $this->groupUser($this->outsider, 'manager');
        $this->groupUser($this->subB, 'manager');
        $own = $this->groupUser($this->subA, 'manager');

        $this->assertSame([$own->id], $this->holders($this->subA->id));
    }

    public function test_an_inactive_user_or_inactive_member_is_skipped(): void
    {
        $inactiveHome = $this->groupUser($this->subA, 'manager');
        $inactiveMember = $this->groupUser($this->head, 'manager', $this->subA);
        $active = $this->groupUser($this->subA, 'manager');
        $inactiveHome->forceFill(['is_active' => false])->save();
        $inactiveMember->forceFill(['is_active' => false])->save();

        $this->assertSame([$active->id], $this->holders($this->subA->id));
    }

    public function test_the_actor_is_left_out(): void
    {
        $actor = $this->groupUser($this->subA, 'manager');
        $other = $this->groupUser($this->subA, 'manager');

        $this->assertSame([$other->id], $this->holders($this->subA->id, $actor->id));
    }

    public function test_a_user_who_is_home_and_member_of_the_same_company_is_returned_once(): void
    {
        $user = $this->groupUser($this->subA, 'manager');
        $this->assertSame(1, DB::table('company_user')->where('user_id', $user->id)->where('company_id', $this->subA->id)->count());

        $this->assertSame([$user->id], $this->holders($this->subA->id));
    }

    public function test_a_permission_nobody_holds_gives_an_empty_collection(): void
    {
        $this->groupUser($this->subA, 'manager');

        $this->assertTrue(StaffRecipients::holding($this->subA->id, 'no.such.permission')->isEmpty());
    }
}
