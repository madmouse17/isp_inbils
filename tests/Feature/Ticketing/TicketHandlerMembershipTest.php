<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketStaffNotification;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * `assigned_to` stays on a ticket when its handler's membership of the company is revoked. Whoever is told of what happens to the
 * ticket afterwards (a staff reopening, a customer's reply, a missed target) is the handler only while they are still an active
 * member of the ticket's company: a technician homed in another company must not read what is said in this one.
 */
class TicketHandlerMembershipTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const REASON = 'Bu Sari 0812-0000-1111 melapor LOS lagi.';

    private User $manager;

    private User $dispatcher;

    /** Homed in Sub A, a member of Sub B where the tickets are. */
    private User $roaming;

    private Customer $customer;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->manager = $this->groupUser($this->subB, 'manager');
        $this->dispatcher = $this->groupUser($this->subB, 'noc');
        $this->roaming = $this->groupUser($this->subA, 'technician', $this->subB);
        $this->customer = Customer::factory()->create(['company_id' => $this->subB->id]);
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->subB->id, 'name' => 'Gangguan', 'code' => 'CAT-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    private function revokeRoaming(): void
    {
        app(CompanyMembershipService::class)->revoke($this->roaming, $this->subB);
    }

    private function closedTicket(?User $handler): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->subB->id, 'code' => 'TKT-'.Str::upper(Str::random(8)), 'title' => 'Internet mati', 'description' => 'Lampu LOS merah',
            'source' => 'customer', 'category_id' => $this->category->id, 'status' => 'closed', 'priority' => 'medium', 'customer_id' => $this->customer->id,
            'assigned_to' => $handler?->id, 'created_by' => $this->manager->id,
            'sla_deadline' => now()->addDay(), 'response_deadline' => now()->addHours(2), 'first_response_at' => now()->subHours(5),
            'resolved_at' => now()->subHours(3), 'resolution_note' => 'Kabel diganti', 'closed_at' => now()->subHour(),
        ]);
    }

    public function test_a_handler_who_left_the_company_is_not_told_of_a_staff_reopening_and_dispatch_is(): void
    {
        $ticket = $this->closedTicket($this->roaming);
        $this->revokeRoaming();
        Notification::fake();

        $this->actingAs($this->manager)->post(route('admin.tickets.reopen', $ticket), ['reason' => self::REASON])->assertRedirect();

        $this->assertSame($this->roaming->id, $ticket->fresh()->assigned_to, 'the stale assignment itself stays');
        Notification::assertNotSentTo($this->roaming, TicketStaffNotification::class);
        Notification::assertSentTo($this->dispatcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'reopened' && $notice->by === 'staff');
        Notification::assertNotSentTo($this->manager, TicketStaffNotification::class);
    }

    public function test_a_handler_homed_elsewhere_who_is_still_a_member_is_told_and_dispatch_is_not(): void
    {
        $ticket = $this->closedTicket($this->roaming);
        Notification::fake();

        $this->actingAs($this->manager)->post(route('admin.tickets.reopen', $ticket), ['reason' => self::REASON])->assertRedirect();

        Notification::assertSentTo($this->roaming, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'reopened' && $notice->companyId === $this->subB->id);
        Notification::assertNotSentTo($this->dispatcher, TicketStaffNotification::class);
    }

    public function test_a_switched_off_handler_is_not_told(): void
    {
        $handler = $this->groupUser($this->subB, 'technician');
        $handler->forceFill(['is_active' => false])->save();
        $ticket = $this->closedTicket($handler);
        Notification::fake();

        $this->actingAs($this->manager)->post(route('admin.tickets.reopen', $ticket), ['reason' => self::REASON])->assertRedirect();

        Notification::assertNotSentTo($handler, TicketStaffNotification::class);
        Notification::assertSentTo($this->dispatcher, TicketStaffNotification::class);
    }

    public function test_whoever_watches_a_ticket_leaves_out_a_handler_who_left_the_company(): void
    {
        $ticket = $this->closedTicket($this->roaming);
        $watching = fn (): array => TicketService::staffWatching($ticket)->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$this->dispatcher->id, $this->manager->id, $this->roaming->id])->sort()->values()->all(), $watching(), 'the handler who is a member, and everyone who may assign');

        $this->revokeRoaming();

        // What a customer's reply and the missed-target job tell.
        $this->assertSame(collect([$this->dispatcher->id, $this->manager->id])->sort()->values()->all(), $watching());
    }
}
