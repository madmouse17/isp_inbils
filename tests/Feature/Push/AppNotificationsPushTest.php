<?php

namespace Tests\Feature\Push;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\Core\Location;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Notifications\InvoiceDueReminderNotification;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Modules\Billing\Notifications\PaymentReceivedNotification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Service\Models\Announcement;
use Modules\Service\Notifications\AnnouncementPublishedNotification;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Notifications\TechnicianEnRouteNotification;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Notifications\TicketCustomerUpdateNotification;
use Modules\Ticketing\Notifications\TicketSlaBreachedNotification;
use Tests\TestCase;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Every event of the apps that already had a bell row also reaches the phone of its recipient, with the words of the bell.
 * Web-only users, and the events that stay in the bell, are untouched.
 */
class AppNotificationsPushTest extends TestCase
{
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private Company $company;

    private User $technician;

    private User $customerUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
        $this->company = Company::factory()->create(['is_active' => true, 'timezone' => 'Asia/Jakarta']);
        $this->technician = $this->technicianLogin($this->company);
        [$this->customerUser, $this->customer] = $this->customerLogin($this->company);
        DeviceToken::factory()->create(['user_id' => $this->technician->id, 'app' => 'technician', 'token' => 'technician-install']);
        DeviceToken::factory()->forApp('customer')->create(['user_id' => $this->customerUser->id, 'token' => 'customer-install']);
    }

    /** @return array<string, string> the data of the only push, whose notification_id is the bell row's */
    private function assertPushed(string $token, string $title, string $body, string $type, string $subjectType, string $subjectId): array
    {
        $this->assertSame([$token], $this->pushedTokens());
        $push = $this->onlyPush();
        $this->assertArrayNotHasKey('notification', $push, 'data-only: the app draws the push itself');
        $this->assertSame(
            ['type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'title' => $title, 'body' => $body],
            array_diff_key($push['data'], ['notification_id' => 1]),
        );

        return $push['data'];
    }

    private function invoice(string $dueDate = '+3 days'): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'total' => 250000,
            'paid_amount' => 0,
            'due_date' => now()->modify($dueDate)->toDateString(),
        ]);
    }

    private function ticket(string $code): Ticket
    {
        return Ticket::factory()->create(['company_id' => $this->company->id, 'code' => $code]);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->company->id, 'status' => 'assigned', 'assigned_to' => $this->technician->id, 'customer_id' => $this->customer->id,
            'scheduled_date' => '2026-10-25',
        ]);
    }

    private function inventoryRequest(string $code): InventoryRequest
    {
        $warehouse = Location::query()->create(['company_id' => $this->company->id, 'code' => 'WH-PUSH', 'name' => 'Gudang', 'type' => 'site', 'path' => 'Gudang', 'is_active' => true]);

        return InventoryRequest::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'requested_by' => $this->technician->id, 'warehouse_location_id' => $warehouse->id,
            'status' => 'ready', 'source_type' => 'installation', 'reason' => 'Instalasi', 'submitted_at' => now(),
        ]);
    }

    public function test_a_ticket_assignment_reaches_the_technician_phone_next_to_the_mail_and_the_bell(): void
    {
        $ticket = Ticket::factory()->create(['company_id' => $this->company->id, 'code' => 'TKT-17', 'priority' => 'high', 'title' => 'Router mati', 'response_deadline' => null]);
        Notification::sendNow($this->technician, new TicketAssignedNotification($ticket->id, 'TKT-17', $this->company->id));

        $data = $this->assertPushed('technician-install', 'Tiket TKT-17 · Tinggi', 'Router mati.', 'ticket_assigned', 'ticket', $ticket->public_id);
        $this->assertSame($this->technician->notifications()->sole()->id, $data['notification_id']);
    }

    public function test_an_sla_breach_reaches_the_phone(): void
    {
        $ticket = $this->ticket('TKT-18');
        Notification::sendNow($this->technician, new TicketSlaBreachedNotification($ticket->id, 'TKT-18', 'resolution', $this->company->id));

        $this->assertPushed('technician-install', 'SLA tiket terlampaui', 'Tiket TKT-18 melewati batas penyelesaian SLA.', 'ticket_sla_breached', 'ticket', $ticket->public_id);
    }

    public function test_a_request_ready_for_pickup_reaches_the_technician_who_asked_for_it(): void
    {
        $request = $this->inventoryRequest('REQ-7');
        Notification::sendNow($this->technician, new InventoryRequestReadyNotification($request->id, 'REQ-7', $this->company->id));

        $this->assertPushed('technician-install', 'Permintaan barang siap diambil', 'Permintaan REQ-7 siap diambil.', 'inventory_request_ready', 'inventory_request', $request->public_id);
    }

    public function test_an_spk_assignment_reaches_the_technician_but_the_customer_visit_date_stays_in_the_bell(): void
    {
        $workOrder = $this->workOrder();

        Notification::sendNow($this->technician, new WorkOrderScheduledNotification($this->company->id, $workOrder->id, 'technician'));
        $this->assertPushed('technician-install', 'SPK baru ditugaskan', 'SPK baru sudah ditugaskan kepada Anda.', 'spk_scheduled', 'work_order', $workOrder->public_id);

        $this->multicasts = [];
        Notification::sendNow($this->customer, new WorkOrderScheduledNotification($this->company->id, $workOrder->id, 'customer'));
        $this->assertSame([], $this->multicasts);
        $this->assertSame('spk_scheduled_customer', $this->customer->notifications()->sole()->data['type']);
    }

    public function test_the_trip_of_the_technician_reaches_the_customer_phone(): void
    {
        $workOrder = $this->workOrder();
        Notification::sendNow($this->customer, new TechnicianEnRouteNotification($this->company->id, $workOrder->id, 'Andi', now()));

        $this->assertPushed('customer-install', 'Teknisi dalam perjalanan', 'Teknisi Andi sedang menuju lokasi Anda.', 'technician_en_route', 'work_order', $workOrder->public_id);
    }

    public function test_an_outage_or_a_maintenance_window_reaches_the_phone_and_an_info_notice_does_not(): void
    {
        $outage = Announcement::factory()->published()->create(['company_id' => $this->company->id, 'kind' => 'outage', 'title' => 'Gangguan jaringan Blok A']);
        Notification::sendNow($this->customer, new AnnouncementPublishedNotification($outage));
        $this->assertPushed('customer-install', 'Pengumuman baru', 'Gangguan jaringan Blok A', 'announcement_published', 'announcement', $outage->public_id);

        $this->multicasts = [];
        $maintenance = Announcement::factory()->published()->create(['company_id' => $this->company->id, 'kind' => 'maintenance']);
        Notification::sendNow($this->customer, new AnnouncementPublishedNotification($maintenance));
        $this->assertCount(1, $this->multicasts);

        $this->multicasts = [];
        $info = Announcement::factory()->published()->create(['company_id' => $this->company->id, 'kind' => 'info']);
        Notification::sendNow($this->customer, new AnnouncementPublishedNotification($info));
        $this->assertSame([], $this->multicasts, 'an info notice stays in the bell');
        $this->assertSame(3, $this->customer->notifications()->count());
    }

    public function test_a_new_invoice_and_a_payment_reach_the_customer_phone(): void
    {
        $invoice = $this->invoice();
        Notification::sendNow($this->customer, new InvoiceIssuedNotification($this->company->id, $invoice->id));
        $this->assertPushed('customer-install', "Tagihan {$invoice->number}", "Tagihan {$invoice->number} sebesar Rp 250.000, jatuh tempo ".$invoice->due_date->locale('id')->translatedFormat('j M Y').'.', 'invoice_issued', 'invoice', $invoice->public_id);

        $this->multicasts = [];
        $payment = Payment::create([
            'company_id' => $this->company->id, 'invoice_id' => $invoice->id, 'amount' => '100000.00', 'method' => 'transfer',
            'reference' => 'BANK-1', 'paid_at' => now(), 'received_by' => User::factory()->create(['company_id' => $this->company->id])->id,
        ]);
        Notification::sendNow($this->customer, new PaymentReceivedNotification($this->company->id, $payment->id));
        $this->assertPushed('customer-install', 'Pembayaran diterima', "Rp 100.000 untuk {$invoice->number} sudah kami terima. Terima kasih.", 'payment_received', 'invoice', $invoice->public_id);
    }

    public function test_the_reminders_tell_due_soon_from_overdue(): void
    {
        $invoice = $this->invoice();

        Notification::sendNow($this->customer, new InvoiceDueReminderNotification($this->company->id, $invoice->id, 'H-3'));
        $this->assertPushed('customer-install', "{$invoice->number} jatuh tempo 3 hari lagi", 'Sisa Rp 250.000. Bayar lewat aplikasi.', 'invoice_due_soon', 'invoice', $invoice->public_id);

        $this->multicasts = [];
        Notification::sendNow($this->customer, new InvoiceDueReminderNotification($this->company->id, $invoice->id, 'H-1'));
        $this->assertSame(['title' => "{$invoice->number} jatuh tempo besok", 'body' => 'Sisa Rp 250.000. Bayar lewat aplikasi.'], array_intersect_key($this->onlyPush()['data'], ['title' => 1, 'body' => 1]));

        $this->multicasts = [];
        $invoice->update(['due_date' => now()->subDay()->toDateString()]);
        Notification::sendNow($this->customer, new InvoiceDueReminderNotification($this->company->id, $invoice->id, 'H+1'));
        $this->assertPushed('customer-install', "{$invoice->number} lewat jatuh tempo", 'Sisa Rp 250.000 belum dibayar sejak '.now()->subDay()->locale('id')->translatedFormat('j M Y').'.', 'invoice_overdue', 'invoice', $invoice->public_id);

        $this->assertEqualsCanonicalizing(['invoice_due_soon', 'invoice_due_soon', 'invoice_overdue'], $this->customer->notifications->pluck('data.type')->all());
    }

    public function test_a_reminder_for_an_invoice_that_got_paid_leaves_neither_bell_row_nor_push(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['status' => 'paid', 'paid_amount' => 250000]);

        Notification::sendNow($this->customer, new InvoiceDueReminderNotification($this->company->id, $invoice->id, 'H-1'));

        $this->assertSame(0, $this->customer->notifications()->count());
        $this->assertSame([], $this->multicasts);
    }

    public function test_the_events_that_stay_in_the_bell_and_users_of_the_web_are_untouched(): void
    {
        $admin = User::factory()->create(['company_id' => $this->company->id]);
        $this->assertSame(['mail', 'database'], (new TicketAssignedNotification(1, 'TKT-1'))->via($admin));
        $this->assertSame(['mail', 'database', FcmChannel::class], (new TicketAssignedNotification(1, 'TKT-1'))->via($this->technician));

        // A customer ticket update is a WhatsApp event of the bell: it does not opt in.
        Notification::sendNow($this->customer, new TicketCustomerUpdateNotification($this->company->id, 5, 'created'));
        $this->assertSame([], $this->multicasts);
    }

    public function test_a_customer_without_the_app_installed_gets_exactly_the_channels_they_got_before(): void
    {
        DeviceToken::query()->delete();

        $this->assertSame([WhatsAppChannel::class, 'database'], (new InvoiceIssuedNotification($this->company->id, 1))->via($this->customer));
    }
}
