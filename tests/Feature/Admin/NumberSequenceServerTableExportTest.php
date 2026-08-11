<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NumberSequenceServerTableExportTest extends TestCase
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

    private function makeSequence(int $companyId, string $entity, string $prefix): NumberSequence
    {
        return NumberSequence::query()->forceCreate([
            'company_id' => $companyId,
            'entity_type' => $entity,
            'prefix' => $prefix,
            'next_number' => 1,
            'padding' => 4,
            'year_suffix' => false,
        ]);
    }

    public function test_index_is_company_scoped_and_searchable(): void
    {
        $this->makeSequence((int) $this->admin->company_id, 'invoice', 'INV');
        $this->makeSequence((int) $this->admin->company_id, 'quote', 'QTE');
        $this->makeSequence((int) $this->otherCompany->id, 'invoice', 'XX');

        $this->actingAs($this->admin)
            ->get(route('admin.number-sequences.index', ['search' => 'INV']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NumberSequences/Index')
                ->has('sequences.data', 1)
                ->where('sequences.data.0.prefix', 'INV')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeSequence((int) $this->admin->company_id, 'invoice', 'INV');
        $this->makeSequence((int) $this->admin->company_id, 'quote', 'QTE');
        $this->makeSequence((int) $this->otherCompany->id, 'invoice', 'OTH');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.number-sequences.export', [
                'format' => 'csv',
                'search' => 'invoice',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('INV', $content);
        $this->assertStringNotContainsString('QTE', $content);
        $this->assertStringNotContainsString('OTH', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'ns-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('number_sequences.view', 'web');
        $role->syncPermissions(['number_sequences.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.number-sequences.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
