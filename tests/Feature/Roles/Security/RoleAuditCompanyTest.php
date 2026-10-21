<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Role;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * L1: `role.attached` and `role.detached` are rows of the trail of the company whose roles changed. They took the company
 * of the user, so the roles a member homed elsewhere got in a company were missing from that company's audit log and
 * showed up in his home company's, with the names of roles that company does not have.
 */
class RoleAuditCompanyTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildGroup();
    }

    public function test_the_roles_a_member_gets_and_loses_in_a_company_are_in_that_companys_trail_not_in_his_home_companys(): void
    {
        $guest = $this->groupUser($this->head, 'staff', $this->subA);
        $subAdmin = $this->groupUser($this->subA);
        Activity::query()->delete();

        $this->actingAs($subAdmin)->put(route('admin.users.roles.update', $guest), ['roles' => ['Teknisi']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rows = Activity::query()->where('description', 'like', 'role.%')->orderBy('id')->get();
        $this->assertSame(['role.detached', 'role.attached'], $rows->pluck('description')->all());
        $this->assertSame([['Staf'], ['Teknisi']], $rows->map(fn (Activity $row): array => $row->properties['roles'])->all());

        foreach ($rows as $row) {
            $this->assertSame('security', $row->log_name);
            $this->assertSame($this->subA->id, (int) $row->company_id, 'the company of the roles, not the home company of the user');
            $this->assertSame(Role::class, $row->subject_type);
            $this->assertSame($this->subA->id, (int) Role::withoutCompany()->findOrFail($row->subject_id)->company_id);
            $this->assertSame($guest->id, $row->properties['user_id']);
            $this->assertSame($subAdmin->id, $row->causer_id);
        }

        $this->assertSame(0, Activity::query()->where('description', 'like', 'role.%')->where('company_id', $this->head->id)->count(), 'nothing in the home company');
    }

    public function test_the_roles_taken_away_with_a_membership_are_in_the_trail_of_that_company(): void
    {
        $guest = $this->groupUser($this->head, 'staff', $this->subA);
        Activity::query()->delete();

        app(CompanyMembershipService::class)->revoke($guest, $this->subA);

        $row = Activity::query()->where('description', 'role.detached')->sole();
        $this->assertSame([$this->subA->id, ['Staf'], $guest->id], [(int) $row->company_id, $row->properties['roles'], $row->properties['user_id']]);
    }
}
