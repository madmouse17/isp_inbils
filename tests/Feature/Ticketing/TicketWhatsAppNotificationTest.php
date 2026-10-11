<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Notifications\TicketCustomerUpdateNotification;
use Modules\Ticketing\Services\TicketService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithWhatsApp;

class TicketWhatsAppNotificationTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Customer $customer;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->company->update(['name' => 'PT Contoh Net']);
        $this->whatsAppEnabledFor($this->company);
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->category = TicketCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Support',
            'code' => 'SUP-1',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
        $this->travelTo('2026-10-14 03:00:00'); // 10:00 in Jakarta
    }

    /** @param array<string, mixed> $attributes */
    private function openTicket(array $attributes = []): Ticket
    {
        return TicketService::create($attributes + [
            'title' => 'Internet mati sejak pagi',
            'description' => 'Lampu LOS merah',
            'source' => 'customer',
            'category_id' => $this->category->id,
            'priority' => 'medium',
            'customer_id' => $this->customer->id,
        ], $this->admin->id);
    }

    private function handler(string $name = 'Budi Santoso'): User
    {
        $handler = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'name' => $name]);
        $handler->assignRole('technician');

        return $handler;
    }

    private function assign(Ticket $ticket, User $handler, ?int $expectedAssignedTo = null): Ticket
    {
        $this->actingAs($this->admin);
        $ticket = $ticket->fresh();

        return TicketService::assign($ticket, $handler->id, $this->admin->id, $expectedAssignedTo, $ticket->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    /** @return list<string> */
    private function parameters(int $nth): array
    {
        return array_column($this->graphRequests()->get($nth)->data()['template']['components'][0]['parameters'], 'text');
    }

    /** @return list<string> template names of every Graph request so far, in order */
    private function templates(): array
    {
        return $this->graphRequests()->map(fn (Request $request): string => $request->data()['template']['name'])->all();
    }

    public function test_creating_a_ticket_for_a_consented_customer_sends_one_message_with_the_ticket_code(): void
    {
        $ticket = $this->openTicket();

        $this->assertSame(['tiket_dibuat'], $this->templates());
        $this->assertSame(['PT Contoh Net', $ticket->code, 'Internet mati sejak pagi'], $this->parameters(0));
        Http::assertSent(fn (Request $request): bool => $request->data()['to'] === '6281234567890');
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('ticket_created', $row->event);
        $this->assertSame('ticket', $row->subject_type);
        $this->assertSame($ticket->id, $row->subject_id);
        $this->assertSame('ticket_created:'.$ticket->id, $row->dedupe_key);
        $this->assertSame($this->customer->id, $row->recipient_id);
        $this->assertSame('sent', $row->status);
    }

    public function test_the_title_is_truncated_to_sixty_characters(): void
    {
        $title = Str::repeat('Gangguan jaringan ', 8);
        $this->openTicket(['title' => $title]);

        $this->assertSame(Str::limit($title, 60), $this->parameters(0)[2]);
    }

    public function test_a_ticket_without_a_customer_leaves_no_whatsapp_row_at_all(): void
    {
        $ticket = $this->openTicket(['customer_id' => null, 'source' => 'internal']);
        $handler = $this->handler();
        $ticket = $this->assign($ticket, $handler);
        $this->actingAs($handler);
        TicketService::startWork($ticket);
        TicketService::resolve($ticket->fresh(), 'Kabel diganti');

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_the_first_assignment_tells_the_customer_who_is_handling_it(): void
    {
        $ticket = $this->openTicket();

        $this->assign($ticket, $this->handler('Budi Santoso'));

        $this->assertSame(['tiket_dibuat', 'tiket_ditangani'], $this->templates());
        $this->assertSame(['PT Contoh Net', $ticket->code, 'Internet mati sejak pagi', 'Budi'], $this->parameters(1));
        $this->assertSame('ticket_assigned:'.$ticket->id, $this->whatsAppRows()->last()->dedupe_key);
    }

    public function test_reassigning_never_sends_a_second_assigned_message(): void
    {
        $ticket = $this->openTicket();
        $first = $this->handler('Budi Santoso');
        $second = $this->handler('Citra Dewi');
        $this->assign($ticket, $first);

        $this->assign($ticket, $second, $first->id);
        $this->assign($ticket, $second, $second->id);

        $this->assertSame(['tiket_dibuat', 'tiket_ditangani'], $this->templates());
    }

    public function test_the_handler_is_not_messaged_on_whatsapp_and_the_existing_mail_notification_stays(): void
    {
        Notification::fake();
        $ticket = $this->openTicket();
        $handler = $this->handler();

        $this->assign($ticket, $handler);

        Notification::assertSentTo($handler, TicketAssignedNotification::class, fn (TicketAssignedNotification $n): bool => $n->ticketId === $ticket->id);
        Notification::assertNotSentTo($handler, TicketCustomerUpdateNotification::class);
        Notification::assertSentTo($this->customer, TicketCustomerUpdateNotification::class, fn (TicketCustomerUpdateNotification $n): bool => $n->stage === 'assigned' && $n->ticketId === $ticket->id && $n->companyId === $this->company->id);
    }

    public function test_resolving_tells_the_customer_the_resolution_date(): void
    {
        $ticket = $this->openTicket();
        $handler = $this->handler();
        $ticket = $this->assign($ticket, $handler);
        $this->actingAs($handler);
        TicketService::startWork($ticket);
        $this->travelTo('2026-10-15 20:30:00'); // 03:30 on 16 October in Jakarta

        TicketService::resolve($ticket->fresh(), 'Kabel fiber diganti');

        $this->assertSame(['tiket_dibuat', 'tiket_ditangani', 'tiket_selesai'], $this->templates());
        $this->assertSame(['PT Contoh Net', $ticket->code, 'Internet mati sejak pagi', '16 Okt 2026'], $this->parameters(2));
        $this->assertSame('ticket_resolved:'.$ticket->id, $this->whatsAppRows()->last()->dedupe_key);
    }

    public function test_an_assignment_that_aborts_with_a_conflict_sends_no_assigned_message(): void
    {
        $ticket = $this->openTicket();
        $handler = $this->handler();

        try {
            TicketService::assign($ticket->fresh(), $handler->id, $this->admin->id, null, now()->subMinute()->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
            $this->fail('the stale state must be refused');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(['tiket_dibuat'], $this->templates());
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_a_rollback_after_the_trigger_sends_nothing(): void
    {
        try {
            DB::transaction(function (): void {
                $this->openTicket();

                throw new RuntimeException('abort after the ticket was created');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Ticket::query()->count());
        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_a_customer_without_consent_is_logged_as_skipped_and_never_called(): void
    {
        $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();

        $this->openTicket();

        Http::assertNothingSent();
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_consent', $row->skip_reason);
    }

    public function test_the_ticket_form_endpoint_sends_the_created_message(): void
    {
        $this->post(route('admin.tickets.store'), [
            'title' => 'Router restart sendiri',
            'description' => 'Sering mati',
            'source' => 'customer',
            'category_id' => $this->category->id,
            'priority' => 'medium',
            'customer_id' => $this->customer->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['tiket_dibuat'], $this->templates());
    }

    public function test_the_jobs_carry_ids_only_on_the_whatsapp_queue_and_are_not_held_back_by_quiet_hours(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-14 15:00:00'); // 22:00 in Jakarta

        $ticket = $this->openTicket();

        $job = Queue::pushed(SendQueuedNotifications::class)->sole();
        $payload = serialize($job);
        foreach (['Internet mati sejak pagi', 'PT Contoh Net', '0812', $ticket->code] as $personal) {
            $this->assertStringNotContainsString($personal, $payload);
        }
        $this->assertSame('whatsapp', $job->queue);
        $this->assertNull($job->delay);
        $this->assertSame(['ticketId' => $ticket->id, 'stage' => 'created', 'companyId' => $this->company->id], [
            'ticketId' => $job->notification->ticketId,
            'stage' => $job->notification->stage,
            'companyId' => $job->notification->companyId,
        ]);
    }

    public function test_a_ticket_cannot_be_opened_for_another_companys_customer_and_nothing_is_sent(): void
    {
        $other = Company::factory()->create(['settings' => ['whatsapp_enabled' => true]]);
        $foreign = $this->consentedCustomer($other, '0813-9999-0001');

        try {
            $this->openTicket(['customer_id' => $foreign->id]);
            $this->fail('a customer of another company must be refused');
        } catch (ModelNotFoundException) {
        }

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TicketCustomerUpdateNotification($this->company->id, 1, 'closed');
    }
}
