<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvaluationServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    private function makeEvaluation(int $companyId, string $employeeName, string $referenceType = 'Ticket'): EmployeeEvaluation
    {
        $employee = User::factory()->create([
            'company_id' => $companyId,
            'name' => $employeeName,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $evaluator = User::factory()->create([
            'company_id' => $companyId,
            'name' => 'Evaluator',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return EmployeeEvaluation::query()->forceCreate([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'reference_type' => $referenceType,
            'reference_id' => 1,
            'score' => 4.5,
            'customer_rating' => 4.0,
            'first_response_minutes' => 15,
            'resolution_minutes' => 60,
            'comment' => 'Feature test evaluation',
            'evaluator_id' => $evaluator->id,
            'evaluated_at' => now(),
        ]);
    }

    public function test_index_is_company_scoped_and_paginated(): void
    {
        foreach (range(1, 11) as $number) {
            $this->makeEvaluation((int) $this->admin->company_id, "Tech {$number}");
        }

        $this->makeEvaluation((int) $this->otherCompany->id, 'Foreign Tech');

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Evaluations/Index')
                ->has('evaluations.data', 1)
                ->where('evaluations.meta.current_page', 2)
                ->where('evaluations.meta.last_page', 2)
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeEvaluation((int) $this->admin->company_id, 'Export Tech');
        $this->makeEvaluation((int) $this->admin->company_id, 'Skip Tech', 'WorkOrder');
        $this->makeEvaluation((int) $this->otherCompany->id, 'Export Tech');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.export', [
                'format' => 'csv',
                'search' => 'Export Tech',
                'reference_type' => 'Ticket',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Export Tech', $content);
        $this->assertStringNotContainsString('Skip Tech', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'evaluation-view-only', 'guard_name' => 'web']);
        Permission::findOrCreate('evaluation.view', 'web');
        $role->syncPermissions(['evaluation.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.evaluations.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
