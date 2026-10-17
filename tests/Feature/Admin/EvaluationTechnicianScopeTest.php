<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * A technician reads only their own evaluations, so the employee filter of the list must not become a way to read the
 * names and emails of their colleagues.
 */
class EvaluationTechnicianScopeTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsCompanyGroup;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $technician;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->technician = $this->named('Teknisi Saya', $this->head);
        $this->colleague = $this->named('Teknisi Rekan', $this->head);
    }

    public function test_the_employee_options_of_a_technician_hold_only_themselves(): void
    {
        $this->assertSame(['Teknisi Saya'], $this->employeeLabels($this->technician, 'Teknisi'));
        $this->assertSame([], $this->employeeLabels($this->technician, 'Rekan'));
    }

    public function test_the_selected_employee_of_a_technician_is_never_a_colleague(): void
    {
        $this->actingAs($this->technician)
            ->get(route('admin.evaluations.index', ['employee_id' => $this->colleague->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedEmployee', null));

        $this->get(route('admin.evaluations.index', ['employee_id' => $this->technician->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedEmployee.value', $this->technician->public_id)
                ->where('selectedEmployee.label', 'Teknisi Saya'));
    }

    public function test_no_colleague_name_or_email_is_in_the_page_of_a_technician(): void
    {
        $props = json_encode(
            $this->actingAs($this->technician)->get(route('admin.evaluations.index', ['employee_id' => $this->colleague->public_id]))->assertOk()->inertiaProps(),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString('Teknisi Rekan', $props);
        $this->assertStringNotContainsString($this->colleague->email, $props);
    }

    public function test_a_reviewer_still_picks_any_evaluable_employee_of_the_company(): void
    {
        $manager = $this->named('Manajer Penilai', $this->head, 'manager');

        $this->assertSame(['Teknisi Rekan', 'Teknisi Saya'], $this->employeeLabels($manager, 'Teknisi'));
    }

    /** @return list<string> */
    private function employeeLabels(User $actor, string $search): array
    {
        return $this->actingAs($actor)
            ->get(route('admin.evaluations.index', ['employee_search' => $search]), $this->partialReload('Admin/Evaluations/Index', 'employeeOptions'))
            ->assertOk()
            ->json('props.employeeOptions.*.label');
    }

    private function named(string $name, Company $home, string $role = 'technician'): User
    {
        $user = $this->groupUser($home, $role);
        $user->forceFill(['name' => $name])->save();

        return $user;
    }
}
