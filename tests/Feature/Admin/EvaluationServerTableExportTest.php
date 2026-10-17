<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\ParsesCsvDownloads;

class EvaluationServerTableExportTest extends TestCase
{
    use BuildsCompanyGroup;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private User $admin;

    /** One evaluator may evaluate a reference once (eval_ref_evaluator_unique), so every fixture row gets its own. */
    private int $nextReferenceId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->admin = $this->groupUser($this->head);
    }

    private function makeEvaluation(Company $company, string $employeeName, string $referenceType = 'Ticket', ?int $referenceId = null): EmployeeEvaluation
    {
        $employee = $this->groupUser($company, 'technician');
        $employee->forceFill(['name' => $employeeName])->save();

        return EmployeeEvaluation::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'evaluator_id' => $this->admin->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId ?? $this->nextReferenceId++,
            'score' => 4.5,
            'customer_rating' => 4.0,
        ]);
    }

    public function test_index_is_company_scoped_and_paginated(): void
    {
        foreach (range(1, 11) as $number) {
            $this->makeEvaluation($this->head, "Tech {$number}");
        }

        $this->makeEvaluation($this->outsider, 'Foreign Tech');

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Evaluations/Index')
                ->has('evaluations.data', 1)
                // The page builds its page buttons from `meta`; the resource carries none of it at the top level.
                ->where('evaluations.meta.current_page', 2)
                ->where('evaluations.meta.last_page', 2)
                ->where('evaluations.meta.per_page', 10)
                ->where('evaluations.meta.total', 11)
                ->missing('evaluations.current_page')
                ->missing('evaluations.last_page')
                ->where('can.export', true)
            );
    }

    public function test_index_and_show_name_the_referenced_ticket_and_spk(): void
    {
        $ticket = Ticket::factory()->create(['company_id' => $this->head->id, 'code' => 'TKT-EVAL-1', 'title' => 'Gangguan fiber']);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->head->id, 'code' => 'SPK-EVAL-1', 'title' => 'Pasang ONT']);
        $onTicket = $this->makeEvaluation($this->head, 'Teknisi Tiket', 'Ticket', $ticket->id);
        $this->makeEvaluation($this->head, 'Teknisi SPK', 'WorkOrder', $workOrder->id);
        $gone = $this->makeEvaluation($this->head, 'Teknisi Hilang', 'Ticket', 999999);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('evaluations.referenceOptions', [
                "Ticket:{$ticket->public_id}" => ['code' => 'TKT-EVAL-1', 'title' => 'Gangguan fiber'],
                "WorkOrder:{$workOrder->public_id}" => ['code' => 'SPK-EVAL-1', 'title' => 'Pasang ONT'],
            ]));

        $this->get(route('admin.evaluations.show', $onTicket))
            ->assertInertia(fn (Assert $page) => $page->where('referenceOption', ['code' => 'TKT-EVAL-1', 'title' => 'Gangguan fiber']));
        $this->get(route('admin.evaluations.show', $gone))
            ->assertInertia(fn (Assert $page) => $page->where('referenceOption', null));
    }

    public function test_employee_filter_resolves_the_selected_option_inside_the_company_only(): void
    {
        $own = $this->makeEvaluation($this->head, 'Teknisi Sendiri');
        $foreign = $this->makeEvaluation($this->outsider, 'Teknisi Asing');

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index', ['employee_id' => $own->employee->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('evaluations.data', 1)
                ->where('selectedEmployee', [
                    'value' => $own->employee->public_id,
                    'label' => 'Teknisi Sendiri',
                    'description' => $own->employee->email,
                ]));

        $this->get(route('admin.evaluations.index', ['employee_id' => $foreign->employee->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('evaluations.data', 0)
                ->where('selectedEmployee', null));
    }

    public function test_export_csv_respects_filters_and_company_scope_with_indonesian_headings(): void
    {
        $this->makeEvaluation($this->head, 'Export Tech');
        $this->makeEvaluation($this->head, 'Skip Tech', 'WorkOrder');
        $this->makeEvaluation($this->outsider, 'Export Tech');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.export', [
                'format' => 'csv',
                'search' => 'Export Tech',
                'reference_type' => 'Ticket',
            ]));

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $records = $this->csvRecords($response);

        $this->assertCount(1, $records);
        $this->assertSame(['Karyawan', 'Referensi', 'Nilai', 'Penilaian pelanggan', 'Penilai', 'Tanggal'], array_keys($records[0]));
        $this->assertSame('Export Tech', $records[0]['Karyawan']);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $role = Role::firstOrCreate(['name' => 'evaluation-view-only', 'guard_name' => 'web']);
        Permission::findOrCreate('evaluation.view', 'web');
        $role->syncPermissions(['evaluation.view']);
        $user = $this->groupUser($this->head, 'evaluation-view-only');

        $this->actingAs($user)
            ->get(route('admin.evaluations.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
