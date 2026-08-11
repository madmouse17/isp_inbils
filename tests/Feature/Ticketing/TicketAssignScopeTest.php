<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketAssignScopeTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_assign_rejects_cross_company_handler(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);

        $this->post(route('admin.tickets.assign', $ticket), ['handler_id' => $outsider->id])
            ->assertSessionHasErrors(['handler_id']);

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_assign_rejects_handler_without_eligible_role(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $noRole = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $noRole->assignRole('customer');

        $this->post(route('admin.tickets.assign', $ticket), ['handler_id' => $noRole->id])
            ->assertSessionHasErrors(['handler_id']);
    }

    public function test_sla_deadline_halved_for_urgent_priority(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-SLA',
            'default_sla_hours' => 8,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $normal = TicketService::computeSlaDeadline($category->id, 'medium');
        $urgent = TicketService::computeSlaDeadline($category->id, 'urgent');

        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $normal->timestamp, 2);
        $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, $urgent->timestamp, 2);
    }

    private function makeOpenTicket(User $admin): Ticket
    {
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Downlink issue',
            'description' => 'Customer offline',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $admin->id);
    }
}
