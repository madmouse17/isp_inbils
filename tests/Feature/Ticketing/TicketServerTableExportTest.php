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

    public function test_priority_filter_matches_index_and_export(): void
    {
        $high = $this->makeTicket((int) $this->admin->company_id, 'Priority High');
        $high->forceFill(['priority' => 'high'])->save();
        $this->makeTicket((int) $this->admin->company_id, 'Priority Medium');

        $this->actingAs($this->admin)
            ->get(route('admin.tickets.index', ['priority' => 'high']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $high->id)
                ->where('filters.priority', 'high')
            );

        $content = $this->get(route('admin.tickets.export', ['priority' => 'high', 'format' => 'csv']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Priority High', $content);
        $this->assertStringNotContainsString('Priority Medium', $content);
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

    public function test_handler_without_assign_permission_only_lists_and_exports_own_tickets(): void
    {
        $handler = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherHandler = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $handlerRole = Role::firstOrCreate(['name' => 'ticket-handler-role', 'guard_name' => 'web']);
        $handlerRole->syncPermissions(['ticket.view', 'ticket.start', 'ticket.resolve', 'ticket.export']);
        $handler->assignRole($handlerRole);
        $otherHandler->assignRole($handlerRole);

        $ownTicket = $this->makeTicket((int) $this->admin->company_id, 'Handler Own Ticket');
        $otherTicket = $this->makeTicket((int) $this->admin->company_id, 'Other Assigned Ticket');
        $unassignedTicket = $this->makeTicket((int) $this->admin->company_id, 'Unassigned Ticket');

        $this->actingAs($this->admin);
        TicketService::assign($ownTicket, $handler->id, $this->admin->id, null, $ownTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        TicketService::assign($otherTicket, $otherHandler->id, $this->admin->id, null, $otherTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        // 1. Index / Paginator list and count must only include own tickets
        $this->actingAs($handler)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $ownTicket->id)
                ->where('tickets.meta.total', 1)
            );

        // 2. Export CSV must only include own tickets
        $exportResponse = $this->actingAs($handler)
            ->get(route('admin.tickets.export', ['format' => 'csv']));

        $exportResponse->assertOk();
        $csvContent = $exportResponse->streamedContent();
        $this->assertStringContainsString('Handler Own Ticket', $csvContent);
        $this->assertStringNotContainsString('Other Assigned Ticket', $csvContent);
        $this->assertStringNotContainsString('Unassigned Ticket', $csvContent);
    }

    public function test_inactive_assigned_actor_lists_and_exports_zero_tickets_and_denied_detail(): void
    {
        $inactiveHandler = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $handlerRole = Role::firstOrCreate(['name' => 'ticket-handler-inactive-role', 'guard_name' => 'web']);
        $handlerRole->syncPermissions(['ticket.view', 'ticket.start', 'ticket.resolve', 'ticket.close', 'ticket.export']);
        $inactiveHandler->assignRole($handlerRole);

        $assignedTicket = $this->makeTicket((int) $this->admin->company_id, 'Inactive Assigned Ticket');
        $unassignedTicket = $this->makeTicket((int) $this->admin->company_id, 'Inactive Unassigned Ticket');

        $this->actingAs($this->admin);
        TicketService::assign($assignedTicket, $inactiveHandler->id, $this->admin->id, null, $assignedTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $inactiveHandler->update(['is_active' => false]);

        // 1. Index list and count returns zero
        $this->actingAs($inactiveHandler)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data', 0)
                ->where('tickets.meta.total', 0)
            );

        // 2. Export returns zero ticket rows (header only)
        $exportResponse = $this->actingAs($inactiveHandler)
            ->get(route('admin.tickets.export', ['format' => 'csv']));

        $exportResponse->assertOk();
        $csvContent = $exportResponse->streamedContent();
        $this->assertStringNotContainsString('Inactive Assigned Ticket', $csvContent);
        $this->assertStringNotContainsString('Inactive Unassigned Ticket', $csvContent);

        // 3. Detail show is forbidden consistent with policy
        $this->actingAs($inactiveHandler)
            ->get(route('admin.tickets.show', $assignedTicket))
            ->assertForbidden();
    }

    public function test_close_and_view_actor_without_assign_only_lists_and_exports_own_tickets(): void
    {
        $closeOnlyHandler = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherHandler = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $closerRole = Role::firstOrCreate(['name' => 'ticket-closer-scope-role', 'guard_name' => 'web']);
        $closerRole->syncPermissions(['ticket.view', 'ticket.close', 'ticket.export']);
        $closeOnlyHandler->assignRole($closerRole);

        $otherRole = Role::firstOrCreate(['name' => 'ticket-other-scope-role', 'guard_name' => 'web']);
        $otherRole->syncPermissions(['ticket.view', 'ticket.start', 'ticket.resolve']);
        $otherHandler->assignRole($otherRole);

        $ownTicket = $this->makeTicket((int) $this->admin->company_id, 'Closer Own Ticket');
        $otherTicket = $this->makeTicket((int) $this->admin->company_id, 'Closer Other Ticket');
        $unassignedTicket = $this->makeTicket((int) $this->admin->company_id, 'Closer Unassigned Ticket');

        $this->actingAs($this->admin);
        TicketService::assign($ownTicket, $closeOnlyHandler->id, $this->admin->id, null, $ownTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        TicketService::assign($otherTicket, $otherHandler->id, $this->admin->id, null, $otherTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        // 1. Index list and count must only include own tickets
        $this->actingAs($closeOnlyHandler)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $ownTicket->id)
                ->where('tickets.meta.total', 1)
            );

        // 2. Export CSV must only include own tickets
        $exportResponse = $this->actingAs($closeOnlyHandler)
            ->get(route('admin.tickets.export', ['format' => 'csv']));

        $exportResponse->assertOk();
        $csvContent = $exportResponse->streamedContent();
        $this->assertStringContainsString('Closer Own Ticket', $csvContent);
        $this->assertStringNotContainsString('Closer Other Ticket', $csvContent);
        $this->assertStringNotContainsString('Closer Unassigned Ticket', $csvContent);

        // 3. Detail show allows own ticket, forbids other and unassigned
        $this->actingAs($closeOnlyHandler)
            ->get(route('admin.tickets.show', $ownTicket))
            ->assertOk();

        $this->actingAs($closeOnlyHandler)
            ->get(route('admin.tickets.show', $otherTicket))
            ->assertForbidden();

        $this->actingAs($closeOnlyHandler)
            ->get(route('admin.tickets.show', $unassignedTicket))
            ->assertForbidden();
    }
}
