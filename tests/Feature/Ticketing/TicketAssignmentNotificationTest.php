<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketAssignmentNotificationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->grantRole($handler, 'technician');

        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00001');

        TicketService::assign($ticket, $handler->id, $admin->id, null, $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

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
        $this->grantRole($handler, 'technician');

        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00002');
        TicketService::assign($ticket, $handler->id, $admin->id, null, $ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        Notification::fake();
        // Force open again with same handler already set — no re-notify when assignee unchanged.
        $ticket->update(['status' => 'open', 'assigned_to' => $handler->id]);
        $ticket = $ticket->fresh();
        TicketService::assign($ticket, $handler->id, $admin->id, $handler->id, $ticket->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        Notification::assertNothingSent();
    }

    public function test_the_mail_names_the_ticket_the_customer_the_priority_and_the_deadline(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        Company::query()->whereKey($admin->company_id)->update(['timezone' => 'Asia/Jakarta']);
        $this->travelTo('2026-06-01 02:00:00'); // 09:00 in Jakarta
        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00003');
        $ticket->update([
            'priority' => 'urgent',
            'customer_id' => Customer::factory()->create(['company_id' => $admin->company_id, 'name' => 'Asep Saepudin'])->id,
            'response_deadline' => '2026-06-02 01:30:00',
        ]);

        $mail = (new TicketAssignedNotification($ticket->id, $ticket->code, $admin->company_id))->toMail($admin);

        $this->assertSame('Tiket TKT-2026-00003 ditugaskan kepada Anda', $mail->subject);
        $this->assertSame(['Downlink issue', 'Pelanggan: Asep Saepudin', 'Prioritas: Mendesak', 'Respons sebelum 08.30 besok.'], $mail->introLines);
        $this->assertSame('Buka tiket', $mail->actionText);
        $this->assertSame(route('admin.tickets.show', $ticket), $mail->actionUrl);
    }

    public function test_the_mail_leaves_in_the_name_of_the_isp_with_its_branded_layout(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        Company::query()->whereKey($admin->company_id)->update(['name' => 'PT Contoh Net']);
        config(['mail.from.address' => 'platform@inbils.test', 'mail.from.name' => 'Inbils']);
        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00004');
        $sent = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent = $event;
        });

        app(ChannelManager::class)->sendNow($admin, new TicketAssignedNotification($ticket->id, $ticket->code, $admin->company_id), ['mail']);

        $this->assertNotNull($sent);
        $from = $sent->message->getFrom()[0];
        $this->assertSame('platform@inbils.test', $from->getAddress());
        $this->assertSame('PT Contoh Net', $from->getName());
        $html = $sent->message->getHtmlBody();
        $this->assertStringContainsString('PT Contoh Net', $html);
        $this->assertStringContainsString('Buka tiket', $html);
        $this->assertStringNotContainsString('© '.date('Y').' Laravel', $html, 'no stock Laravel footer');
        $this->assertStringContainsString('PT Contoh Net', $sent->message->getTextBody());
        $this->assertStringContainsString(route('admin.tickets.show', $ticket), $sent->message->getTextBody());
    }

    public function test_a_title_typed_by_a_customer_cannot_make_a_link_in_the_mail(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin, 'TKT-2026-00005');
        $ticket->update(['title' => '[Bayar sekarang](https://evil.test/pay) <b>x</b>']);
        $sent = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent = $event;
        });

        app(ChannelManager::class)->sendNow($admin, new TicketAssignedNotification($ticket->id, $ticket->code, $admin->company_id), ['mail']);

        $html = $sent->message->getHtmlBody();
        $this->assertStringContainsString('[Bayar sekarang]', $html, 'the words stay');
        $this->assertStringNotContainsString('>Bayar sekarang</a>', $html, 'no label that hides its target');
        $this->assertStringNotContainsString('<b>x</b>', $html);
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
