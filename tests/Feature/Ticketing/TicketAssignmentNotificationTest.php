<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketAssignmentNotificationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'web']);
    }

    public function test_assign_notifies_assignee(): void
    {
        Notification::fake();

        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        $handler = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $handler->assignRole('technician');

        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00001');

        TicketService::assign($ticket, $handler->id, $admin->id);

        Notification::assertSentTo($handler, TicketAssignedNotification::class, function ($n) use ($ticket) {
            return $n->ticketId === $ticket->id;
        });
    }

    public function test_assign_does_not_renotify_same_handler(): void
    {
        Notification::fake();

        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        $handler = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $handler->assignRole('technician');

        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00002');
        TicketService::assign($ticket, $handler->id, $admin->id);

        Notification::fake();
        // Force open again with same handler already set — no re-notify when assignee unchanged.
        $ticket->update(['status' => 'open', 'assigned_to' => $handler->id]);
        TicketService::assign($ticket->fresh(), $handler->id, $admin->id);

        Notification::assertNothingSent();
    }

    private function makeOpenTicket(User $admin, string $code): Ticket
    {
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.$code,
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::create([
            'company_id' => $admin->company_id,
            'code' => $code,
            'title' => 'Downlink issue',
            'description' => 'Customer offline',
            'source' => 'customer',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'sla_deadline' => now()->addHours(24),
            'created_by' => $admin->id,
        ]);
    }
}
