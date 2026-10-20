<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketStaffNotification;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * F6-D05: dispatch hears of every new ticket and, with the handler, of every customer reply. Staff are found through the
 * permission (ticket.assign), never a role name, and a notice never goes to the customer or to the person who caused it.
 */
class TicketStaffNoticeTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->company->id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    private function staff(string $permission, bool $active = true, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id, 'is_active' => $active]);
        $user->givePermissionTo($permission);

        return $user;
    }

    /** @return array{0: User, 1: Customer} */
    private function customer(string $name = 'Asep Saepudin'): array
    {
        [$user, $customer] = $this->customerLogin($this->company);
        $customer->forceFill(['name' => $name])->save();

        return [$user, $customer];
    }

    /** @return array<string, mixed> */
    private function newTicket(?Customer $customer = null): array
    {
        return [
            'title' => 'Internet mati', 'description' => 'Lampu LOS merah', 'source' => 'customer', 'priority' => 'medium',
            'category_id' => $this->category->id, 'customer_id' => $customer?->id,
        ];
    }

    public function test_new_ticket_notifies_assign_holders_except_actor(): void
    {
        Notification::fake();
        $dispatchers = [$this->staff('ticket.assign'), $this->staff('ticket.assign')];
        $leftOut = [
            $this->admin, // the actor
            $this->staff('ticket.assign', active: false),
            $this->staff('ticket.view'),
            $this->staff('ticket.assign', company: Company::factory()->create(['is_active' => true])),
        ];
        $this->actingAs($this->admin);

        $ticket = TicketService::create($this->newTicket(), $this->admin->id);

        foreach ($dispatchers as $dispatcher) {
            Notification::assertSentTo($dispatcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'created' && $notice->ticketId === $ticket->id && $notice->companyId === $this->company->id);
        }
        foreach ($leftOut as $user) {
            Notification::assertNotSentTo($user, TicketStaffNotification::class);
        }
    }

    public function test_a_ticket_made_in_the_app_reaches_the_bell_of_dispatch_but_not_of_the_customer(): void
    {
        [$customerUser, $customer] = $this->customer('Asep Saepudin');
        $dispatcher = $this->staff('ticket.assign');

        $token = $this->issueToken($customerUser, 'customer');
        $ticketId = $this->api('POST', 'customer/tickets', [
            'category_id' => $this->category->public_id, 'title' => 'Internet mati', 'description' => 'Lampu LOS merah',
        ], $token)->assertCreated()->json('data.id');
        $ticket = Ticket::withoutCompany()->wherePublicId($ticketId)->firstOrFail();

        $row = $dispatcher->notifications()->sole();
        $this->assertEquals([
            'type' => 'ticket_created',
            'ticket_id' => $ticket->public_id,
            'ticket_code' => $ticket->code,
            'company_id' => $this->company->id,
            'customer_name' => 'Asep Saepudin',
        ], $row->data);

        $bell = (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications'));
        $this->assertSame('Tiket baru', $bell['title']);
        $this->assertSame("Tiket {$ticket->code} dari Asep Saepudin menunggu penugasan.", $bell['message']);
        $this->assertSame(['type' => 'ticket', 'id' => $ticket->public_id], $bell['subject']);

        // The customer keeps hearing "dibuat", in their own words.
        $own = $customer->notifications()->sole();
        $this->assertSame('ticket_created', $own->data['type']);
        $this->assertSame('Tiket dibuat', (new NotificationResource($own))->resolve(Request::create('/api/v1/notifications'))['title']);
    }

    public function test_customer_reply_notifies_assignee_and_dispatch(): void
    {
        Notification::fake();
        [$customerUser, $customer] = $this->customer();
        $handler = $this->staff('ticket.start');
        $dispatcher = $this->staff('ticket.assign');
        $assigningHandler = $this->staff('ticket.assign');
        $inactiveHandler = $this->staff('ticket.start', active: false);
        $busy = Ticket::query()->create($this->ticketRow($customer, $customerUser, ['status' => 'on_progress', 'assigned_to' => $handler->id]));
        $dispatchHandled = Ticket::query()->create($this->ticketRow($customer, $customerUser, ['status' => 'assigned', 'assigned_to' => $assigningHandler->id]));
        $unattended = Ticket::query()->create($this->ticketRow($customer, $customerUser, ['status' => 'assigned', 'assigned_to' => $inactiveHandler->id]));
        $token = $this->issueToken($customerUser, 'customer');

        $this->api('POST', "customer/tickets/{$busy->public_id}/comments", ['body' => 'Sudah lewat sehari'], $token)->assertCreated();
        Notification::assertSentTo($handler, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'customer_replied' && $notice->ticketId === $busy->id);
        Notification::assertSentTo($dispatcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->ticketId === $busy->id);

        // A handler who is also dispatch hears it once.
        $this->api('POST', "customer/tickets/{$dispatchHandled->public_id}/comments", ['body' => 'Mohon dicek'], $token)->assertCreated();
        $this->assertSame(1, Notification::sent($assigningHandler, TicketStaffNotification::class)->filter(fn (TicketStaffNotification $notice): bool => $notice->ticketId === $dispatchHandled->id)->count());

        // An inactive handler is not a recipient; dispatch still is.
        $this->api('POST', "customer/tickets/{$unattended->public_id}/comments", ['body' => 'Halo?'], $token)->assertCreated();
        Notification::assertNotSentTo($inactiveHandler, TicketStaffNotification::class);
        Notification::assertSentTo($dispatcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->ticketId === $unattended->id);

        Notification::assertNotSentTo($customerUser, TicketStaffNotification::class);
        Notification::assertNotSentTo($customer, TicketStaffNotification::class);
    }

    public function test_customer_reply_notice_reads_in_indonesian_in_the_bell_of_the_handler(): void
    {
        [$customerUser, $customer] = $this->customer();
        $handler = $this->staff('ticket.start');
        $ticket = Ticket::query()->create($this->ticketRow($customer, $customerUser, ['status' => 'on_progress', 'assigned_to' => $handler->id]));

        $this->api('POST', "customer/tickets/{$ticket->public_id}/comments", ['body' => 'Sudah lewat sehari'], $this->issueToken($customerUser, 'customer'))->assertCreated();

        $row = $handler->notifications()->sole();
        $this->assertEquals([
            'type' => 'ticket_customer_replied',
            'ticket_id' => $ticket->public_id,
            'ticket_code' => $ticket->code,
            'company_id' => $this->company->id,
        ], $row->data);
        $bell = (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications'));
        $this->assertSame('Pelanggan membalas', $bell['title']);
        $this->assertSame("Pelanggan membalas tiket {$ticket->code}.", $bell['message']);
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TicketStaffNotification($this->company->id, 1, 'closed');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ticketRow(Customer $customer, User $customerUser, array $overrides): array
    {
        return $overrides + [
            'company_id' => $this->company->id, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet mati', 'description' => 'Lampu LOS merah',
            'source' => 'customer', 'category_id' => $this->category->id, 'priority' => 'medium', 'customer_id' => $customer->id,
            'created_by' => $customerUser->id, 'sla_deadline' => now()->addDay(),
        ];
    }
}
