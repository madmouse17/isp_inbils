<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketCloseTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->createCompanyUser();
        $this->technician = $this->staffUser('technician');
        $this->manager = $this->staffUser('manager');
    }

    public function test_manager_closes_ticket_resolved_by_technician(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');

        $this->actingAs($this->manager)
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk();

        $this->actingAs($this->manager)
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($this->technician->id, $ticket->assigned_to);
    }

    public function test_admin_closes_ticket_resolved_by_technician(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');

        $this->actingAs($this->admin)
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect();

        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_technician_cannot_close_own_resolved_ticket(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');

        $this->actingAs($this->technician)
            ->post(route('admin.tickets.close', $ticket))
            ->assertForbidden();

        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_the_handler_who_resolved_cannot_close_even_with_ticket_close(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');
        // The admin holds ticket.close; as the handler it must still leave closing to a checker.
        $ticket->forceFill(['assigned_to' => $this->admin->id])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.tickets.close', $ticket))
            ->assertForbidden();

        try {
            TicketService::close($ticket->fresh());
            $this->fail('The handler closed its own ticket.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_manager_cannot_close_unresolved_ticket(): void
    {
        $ticket = $this->ticketHandledByTechnician('on_progress');

        $this->actingAs($this->manager)
            ->post(route('admin.tickets.close', $ticket))
            ->assertForbidden();

        $this->assertSame('on_progress', $ticket->fresh()->status);
    }

    public function test_manager_cannot_close_other_company_ticket(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');
        $foreignManager = User::factory()->create([
            'company_id' => $this->createCompanyUser()->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $foreignManager->assignRole('manager');

        $this->actingAs($foreignManager)
            ->post(route('admin.tickets.close', $ticket))
            ->assertNotFound();

        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_show_offers_close_only_to_a_checker(): void
    {
        $ticket = $this->ticketHandledByTechnician('resolved');
        $canClose = fn (User $user): bool => $this->actingAs($user)
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()->viewData('page')['props']['ticket']['data']['can']['close'];

        $this->assertTrue($canClose($this->manager));
        $this->assertFalse($canClose($this->technician));

        // The admin as resolving handler is not offered Close, though it holds ticket.close.
        $ticket->forceFill(['assigned_to' => $this->admin->id])->save();
        $this->assertFalse($canClose($this->admin));
    }

    private function staffUser(string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function ticketHandledByTechnician(string $status): Ticket
    {
        $this->actingAs($this->admin);
        $category = TicketCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $ticket = TicketService::create([
            'title' => 'Downlink issue',
            'description' => 'Customer offline',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $this->admin->id);
        TicketService::assign($ticket, $this->technician->id, $this->admin->id, null, $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        $this->actingAs($this->technician);
        TicketService::startWork($ticket->fresh());

        if ($status === 'resolved') {
            TicketService::resolve($ticket->fresh(), 'Replaced the faulty ONT');
        }

        return $ticket->fresh();
    }
}
