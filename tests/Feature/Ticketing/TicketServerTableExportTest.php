<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketServerTableExportTest extends TestCase
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

    private function makeTicket(int $companyId, string $title, string $status = 'open'): Ticket
    {
        $category = TicketCategory::query()->forceCreate([
            'company_id' => $companyId,
            'name' => 'Support '.$title,
            'code' => 'SUP-'.$companyId.'-'.substr(md5($title), 0, 8),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'company_id' => $companyId,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $ticket = TicketService::create([
            'title' => $title,
            'description' => 'Created for feature test',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => null,
            'subscription_id' => null,
            'network_asset_id' => null,
            'location_id' => null,
        ], $user->id);

        if ($status !== 'open') {
            $ticket->forceFill(['status' => $status])->save();
        }

        return $ticket;
    }

    public function test_index_is_company_scoped_and_paginated(): void
    {
        foreach (range(1, 11) as $number) {
            $this->makeTicket((int) $this->admin->company_id, "Ticket {$number}");
        }

        $this->makeTicket((int) $this->otherCompany->id, 'Foreign Ticket');

        $this->actingAs($this->admin)
            ->get(route('admin.tickets.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.meta.current_page', 2)
                ->where('tickets.meta.last_page', 2)
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeTicket((int) $this->admin->company_id, 'Export Fiber Outage');
        $this->makeTicket((int) $this->admin->company_id, 'Skip Fiber Outage', 'closed');
        $this->makeTicket((int) $this->otherCompany->id, 'Export Fiber Outage');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.tickets.export', [
                'format' => 'csv',
                'search' => 'Export Fiber',
                'status' => 'open',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Export Fiber Outage', $content);
        $this->assertStringNotContainsString('Skip Fiber Outage', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'ticket-view-only', 'guard_name' => 'web']);
        Permission::findOrCreate('ticket.view', 'web');
        $role->syncPermissions(['ticket.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.tickets.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
