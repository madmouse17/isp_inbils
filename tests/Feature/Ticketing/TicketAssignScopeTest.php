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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

class TicketAssignScopeTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_direct_service_assign_requires_expected_assignment_state(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');

        try {
            TicketService::assign($ticket, $handler->id, $admin->id);
            $this->fail('Missing assignment state must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_direct_service_assign_accepts_explicit_unassigned_state(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');

        TicketService::assign(
            $ticket,
            $handler->id,
            $admin->id,
            null,
            $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z')
        );

        $this->assertSame($handler->id, $ticket->fresh()->assigned_to);
    }

    public function test_assign_succeeds_with_the_state_the_show_page_sends(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');

        // Post exactly what the Show page sends, so a resource that drops updated_at fails here.
        $shown = $this->get(route('admin.tickets.show', $ticket))
            ->assertOk()->viewData('page')['props']['ticket']['data'];

        $this->post(route('admin.tickets.assign', $ticket), [
            'handler_id' => $handler->public_id,
            'expected_assigned_to' => $shown['assigned_to'] ?? null,
            'expected_updated_at' => $shown['updated_at'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($handler->id, $ticket->fresh()->assigned_to);
    }

    public function test_direct_service_assign_rejects_stale_assignment_state(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(409);
        TicketService::assign($ticket, $handler->id, $admin->id, null, now()->subMinute()->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    public function test_assign_rejects_cross_company_handler(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);

        $this->post(route('admin.tickets.assign', $ticket), [
            'handler_id' => $outsider->public_id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        ])
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

        $this->post(route('admin.tickets.assign', $ticket), [
            'handler_id' => $noRole->public_id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        ])
            ->assertSessionHasErrors(['handler_id']);
    }

    public function test_assign_accepts_same_company_handler_with_lifecycle_capability(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');

        $this->post(route('admin.tickets.assign', $ticket), [
            'handler_id' => $handler->public_id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        ])
            ->assertRedirect();

        $this->actingAs($handler)
            ->post(route('admin.tickets.start', $ticket))
            ->assertRedirect();

        $this->assertSame('on_progress', $ticket->fresh()->status);
    }

    public function test_ticket_lifecycle_requires_assigned_handler(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $this->post(route('admin.tickets.start', $ticket))
            ->assertStatus(403);

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_ticket_lifecycle_rejects_other_assignee(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $handler->assignRole('technician');
        $otherHandler = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $otherHandler->assignRole('technician');
        TicketService::assign($ticket, $handler->id, $admin->id, null, $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        $this->actingAs($otherHandler)
            ->post(route('admin.tickets.start', $ticket))
            ->assertNotFound();

        $this->assertSame('assigned', $ticket->fresh()->status);
    }

    public function test_ticket_close_requires_close_permission_not_handler_assignment(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        // Prepare a resolved ticket
        $handler = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $handlerRole = Role::firstOrCreate(['name' => 'ticket-lifecycle-worker', 'guard_name' => 'web']);
        $handlerRole->givePermissionTo(['ticket.start', 'ticket.resolve', 'ticket.close']);
        $handler->assignRole($handlerRole);

        TicketService::assign($ticket, $handler->id, $admin->id, null, $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        $this->actingAs($handler)
            ->post(route('admin.tickets.start', $ticket))
            ->assertRedirect();

        $this->actingAs($handler)
            ->post(route('admin.tickets.resolve', $ticket), ['resolution_note' => 'Resolved issue properly'])
            ->assertRedirect();

        $this->assertSame('resolved', $ticket->fresh()->status);

        // 1. Same-company actor without ticket.close cannot close
        $otherCloser = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $otherCloserRole = Role::firstOrCreate(['name' => 'ticket-viewer-only', 'guard_name' => 'web']);
        $otherCloserRole->givePermissionTo(['ticket.view']);
        $otherCloser->assignRole($otherCloserRole);

        $this->actingAs($otherCloser)
            ->post(route('admin.tickets.close', $ticket))
            ->assertStatus(403);

        $this->assertSame('resolved', $ticket->fresh()->status);

        // 2. Direct service close by an actor lacking ticket.close also throws 403
        try {
            TicketService::close($ticket);
            $this->fail('Expected 403 when actor without ticket.close attempts service close.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('resolved', $ticket->fresh()->status);

        // 3. The handler who resolved cannot close (maker/checker); another ticket.close holder can
        $this->actingAs($handler)
            ->post(route('admin.tickets.close', $ticket))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect();

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->closed_at);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'ticket',
            'description' => 'closed',
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
        ]);

        // 4. Foreign tenant fail-closed on direct close
        $foreignAdmin = $this->createCompanyUser();
        $this->actingAs($foreignAdmin);
        $foreignTicket = $this->makeOpenTicket($foreignAdmin);
        TicketService::assign($foreignTicket, $foreignAdmin->id, $foreignAdmin->id, null, $foreignTicket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $foreignTicket->update(['status' => 'resolved']);

        $this->actingAs($handler)
            ->post(route('admin.tickets.close', $foreignTicket))
            ->assertStatus(404);
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
