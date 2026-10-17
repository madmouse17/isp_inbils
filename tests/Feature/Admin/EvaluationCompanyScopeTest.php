<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsCompanyGroup;

/**
 * The Evaluasi forms. `User` has no company scope, so the employee who is offered, searched and submitted comes from the
 * `company_user` membership of the company the evaluator acts in; tickets and SPK come from their own company scope.
 */
class EvaluationCompanyScopeTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        // Homed in the head company and a member of subsidiary A, so the switch into A is valid.
        $this->admin = $this->groupUser($this->head, 'admin', $this->subA);
    }

    private function employee(string $name, Company $home, string $role = 'technician', Company ...$others): User
    {
        $user = $this->groupUser($home, $role, ...$others);
        $user->forceFill(['name' => $name])->save();

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function finishedTicket(Company $company, ?User $handler, array $attributes = []): Ticket
    {
        return Ticket::factory()->create([
            'company_id' => $company->id,
            'status' => 'closed',
            'assigned_to' => $handler?->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $employee, string $type, int|string $referenceId, array $overrides = []): array
    {
        return ['employee_id' => $employee->public_id, 'reference_type' => $type, 'reference_id' => $referenceId, 'score' => 4.5, ...$overrides];
    }

    public function test_the_pages_never_send_an_employee_of_another_company(): void
    {
        $this->employee('Teknisi Sendiri', $this->head);
        $foreign = $this->employee('Teknisi Asing', $this->outsider);

        foreach (['admin.evaluations.index', 'admin.evaluations.create'] as $route) {
            $props = json_encode(
                $this->actingAs($this->admin)->get(route($route))->assertOk()->inertiaProps(),
                JSON_THROW_ON_ERROR,
            );

            $this->assertStringNotContainsString('Teknisi Asing', $props, $route);
            $this->assertStringNotContainsString($foreign->email, $props, $route);
        }
    }

    public function test_employee_options_offer_active_evaluable_members_of_the_effective_company(): void
    {
        $this->employee('Karyawan Teknisi', $this->head);
        $this->employee('Karyawan Staf', $this->head, 'staff');
        $this->employee('Karyawan Bersama', $this->head, 'technician', $this->subA);
        $this->employee('Karyawan Cabang A', $this->subA);
        $inactive = $this->employee('Karyawan Nonaktif', $this->head);
        $inactive->forceFill(['is_active' => false])->save();
        $this->employee('Karyawan Manajer', $this->head, 'manager');
        $this->employee('Karyawan Pelanggan', $this->head, 'customer');
        $this->employee('Karyawan Asing', $this->outsider);
        $this->employee('Karyawan Tanpa Keanggotaan', $this->subA)->companies()->detach();

        $labels = fn (array $query, ?int $company = null) => $this->actingAs($this->admin)
            ->withSession($company === null ? [] : [CompanyService::SESSION_KEY => $company])
            ->get(route('admin.evaluations.index', $query), $this->partialReload('Admin/Evaluations/Index', 'employeeOptions'))
            ->assertOk()
            ->json('props.employeeOptions.*.label');

        $this->assertSame(
            ['Karyawan Bersama', 'Karyawan Staf', 'Karyawan Teknisi'],
            $labels(['employee_search' => 'Karyawan']),
        );
        $this->assertSame(
            ['Karyawan Bersama', 'Karyawan Cabang A'],
            $labels(['employee_search' => 'Karyawan'], $this->subA->id),
        );
    }

    public function test_an_active_employee_filter_still_lets_the_picker_find_other_employees(): void
    {
        $selected = $this->employee('Karyawan Teknisi', $this->head);
        $this->employee('Karyawan Staf', $this->head, 'staff');

        $labels = $this->actingAs($this->admin)
            ->get(
                route('admin.evaluations.index', ['employee_id' => $selected->public_id, 'employee_search' => 'Karyawan']),
                $this->partialReload('Admin/Evaluations/Index', 'employeeOptions'),
            )
            ->assertOk()
            ->json('props.employeeOptions.*.label');

        $this->assertContains('Karyawan Staf', $labels);
        $this->assertContains('Karyawan Teknisi', $labels);
    }

    public function test_reference_options_offer_the_evaluable_records_of_the_company(): void
    {
        $employee = $this->employee('Teknisi Sendiri', $this->head);
        $foreign = $this->employee('Teknisi Asing', $this->outsider);

        $ticket = $this->finishedTicket($this->head, $employee, ['code' => 'TKT-OPS-OWN', 'title' => 'Gangguan Evaluasi']);
        $this->finishedTicket($this->head, $employee, ['code' => 'TKT-OPS-OPEN', 'title' => 'Gangguan Evaluasi', 'status' => 'open']);
        $this->finishedTicket($this->head, null, ['code' => 'TKT-OPS-NOBODY', 'title' => 'Gangguan Evaluasi']);
        $this->finishedTicket($this->outsider, $foreign, ['code' => 'TKT-OPS-FOREIGN', 'title' => 'Gangguan Evaluasi']);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->head->id, 'code' => 'SPK-OPS-OWN', 'title' => 'Gangguan Evaluasi', 'status' => 'completed', 'assigned_to' => $employee->id]);
        WorkOrderFactory::new()->create(['company_id' => $this->head->id, 'code' => 'SPK-OPS-DRAFT', 'title' => 'Gangguan Evaluasi', 'status' => 'draft', 'assigned_to' => $employee->id]);
        WorkOrderFactory::new()->create(['company_id' => $this->outsider->id, 'code' => 'SPK-OPS-FOREIGN', 'title' => 'Gangguan Evaluasi', 'status' => 'completed', 'assigned_to' => $foreign->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket_search' => 'Gangguan']), $this->partialReload('Admin/Evaluations/Create', 'ticketOptions'))
            ->assertOk()
            ->assertJsonPath('props.ticketOptions', [['value' => $ticket->public_id, 'label' => 'TKT-OPS-OWN', 'description' => 'Gangguan Evaluasi']]);
        $this->get(route('admin.evaluations.create', ['workOrder_search' => 'Gangguan']), $this->partialReload('Admin/Evaluations/Create', 'workOrderOptions'))
            ->assertOk()
            ->assertJsonPath('props.workOrderOptions', [['value' => $workOrder->public_id, 'label' => 'SPK-OPS-OWN', 'description' => 'Gangguan Evaluasi']]);
    }

    public function test_store_snapshots_the_response_and_resolution_time_and_answers_in_indonesian(): void
    {
        $employee = $this->employee('Teknisi Sendiri', $this->head);
        $ticket = $this->finishedTicket($this->head, $employee, [
            'created_at' => '2026-03-02 09:00:00',
            'first_response_at' => '2026-03-02 10:00:00',
            'resolved_at' => '2026-03-02 11:00:00',
        ]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->head->id,
            'status' => 'completed',
            'assigned_to' => $employee->id,
            'started_at' => '2026-03-02 09:00:00',
            'completed_at' => '2026-03-02 10:30:00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.store'), $this->payload($employee, 'Ticket', $ticket->public_id, ['customer_rating' => 4, 'comment' => 'Cepat dan rapi']))
            ->assertRedirect(route('admin.evaluations.index'))
            ->assertSessionHas('success', 'Evaluasi berhasil dibuat.');
        $this->post(route('admin.evaluations.store'), $this->payload($employee, 'WorkOrder', $workOrder->public_id))
            ->assertRedirect(route('admin.evaluations.index'));

        $onTicket = EmployeeEvaluation::query()->where('reference_type', 'Ticket')->sole();
        $this->assertSame($this->admin->id, $onTicket->evaluator_id);
        $this->assertSame($employee->id, $onTicket->employee_id);
        $this->assertSame(60, $onTicket->first_response_minutes);
        $this->assertSame(120, $onTicket->resolution_minutes);

        $onWorkOrder = EmployeeEvaluation::query()->where('reference_type', 'WorkOrder')->sole();
        $this->assertNull($onWorkOrder->first_response_minutes);
        $this->assertSame(90, $onWorkOrder->resolution_minutes);
    }

    public function test_store_follows_the_company_the_evaluator_switched_into(): void
    {
        // Homed in the head company, a member of subsidiary A: an employee of A while the evaluator acts in A.
        $employee = $this->employee('Teknisi Bersama', $this->head, 'technician', $this->subA);
        $ticket = $this->finishedTicket($this->subA, $employee);

        $this->actingAs($this->admin)
            ->withSession([CompanyService::SESSION_KEY => $this->subA->id])
            ->post(route('admin.evaluations.store'), $this->payload($employee, 'Ticket', $ticket->public_id))
            ->assertRedirect(route('admin.evaluations.index'));

        $this->assertSame($this->subA->id, (int) EmployeeEvaluation::withoutCompany()->sole()->company_id);
    }

    public function test_store_rejects_employees_who_are_foreign_inactive_or_not_evaluable(): void
    {
        $handler = $this->employee('Teknisi Sendiri', $this->head);
        $ticket = $this->finishedTicket($this->head, $handler);
        $inactive = $this->employee('Teknisi Nonaktif', $this->head);
        $inactive->forceFill(['is_active' => false])->save();

        $cases = [
            ['karyawan yang dipilih tidak valid.', $this->employee('Teknisi Asing', $this->outsider)],
            ['karyawan yang dipilih tidak valid.', $inactive],
            ['karyawan yang dipilih harus berupa pengguna aktif dengan peran yang sesuai.', $this->employee('Manajer Sendiri', $this->head, 'manager')],
        ];

        foreach ($cases as [$message, $user]) {
            $this->actingAs($this->admin)
                ->post(route('admin.evaluations.store'), $this->payload($user, 'Ticket', $ticket->public_id))
                ->assertSessionHasErrors(['employee_id' => $message]);
            $this->assertCount(1, session('errors')->get('employee_id'), 'one message per rejected employee');
        }

        $this->assertSame(0, EmployeeEvaluation::withoutCompany()->count());
    }

    public function test_store_rejects_references_of_another_company(): void
    {
        $employee = $this->employee('Teknisi Sendiri', $this->head);
        $foreign = $this->employee('Teknisi Asing', $this->outsider);
        $foreignTicket = $this->finishedTicket($this->outsider, $foreign);
        $foreignWorkOrder = WorkOrderFactory::new()->create(['company_id' => $this->outsider->id, 'status' => 'completed', 'assigned_to' => $foreign->id]);

        foreach ([['Ticket', $foreignTicket->public_id], ['WorkOrder', $foreignWorkOrder->public_id], ['Ticket', PublicId::new()], ['Ticket', 999999]] as [$type, $id]) {
            $this->actingAs($this->admin)
                ->post(route('admin.evaluations.store'), $this->payload($employee, $type, $id))
                ->assertSessionHasErrors(['reference_id' => 'referensi yang dipilih tidak valid untuk perusahaan ini.']);
        }

        $this->assertSame(0, EmployeeEvaluation::withoutCompany()->count());
    }

    public function test_store_validation_messages_are_indonesian(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.store'), [])
            ->assertSessionHasErrors([
                'employee_id' => 'karyawan wajib diisi.',
                'reference_type' => 'jenis referensi wajib diisi.',
                'reference_id' => 'referensi wajib diisi.',
                'score' => 'nilai wajib diisi.',
            ]);

        $employee = $this->employee('Teknisi Sendiri', $this->head);
        $this->post(route('admin.evaluations.store'), $this->payload($employee, 'User', 1, [
            'score' => 6,
            'customer_rating' => 0,
            'comment' => str_repeat('a', 1001),
        ]))->assertSessionHasErrors([
            'reference_type' => 'jenis referensi yang dipilih tidak valid.',
            'reference_id' => 'Jenis referensi yang dipilih tidak diizinkan.',
            'score' => 'nilai harus bernilai antara 1.0 dan 5.0.',
            'customer_rating' => 'penilaian pelanggan harus bernilai antara 1.0 dan 5.0.',
            'comment' => 'komentar tidak boleh lebih dari 1000 karakter.',
        ]);
    }

    public function test_update_and_delete_stay_inside_the_company_and_answer_in_indonesian(): void
    {
        $own = EmployeeEvaluation::factory()->create(['company_id' => $this->head->id]);
        $foreign = EmployeeEvaluation::factory()->create(['company_id' => $this->outsider->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.evaluations.update', $own), ['score' => 3.5, 'comment' => 'Perlu pembinaan'])
            ->assertRedirect(route('admin.evaluations.index'))
            ->assertSessionHas('success', 'Evaluasi berhasil diubah.');
        $this->assertSame('3.5', $own->fresh()->score);

        $this->put(route('admin.evaluations.update', $foreign), ['score' => 1])->assertNotFound();
        $this->delete(route('admin.evaluations.destroy', $foreign))->assertNotFound();

        $this->delete(route('admin.evaluations.destroy', $own))->assertSessionHas('success', 'Evaluasi berhasil dihapus.');
        $this->assertSoftDeleted($own);
        $this->assertNotSoftDeleted($foreign);
    }
}
