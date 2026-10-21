<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * F12-D11: the bell and the push say which invoice, how much, by when, which ticket at what priority and who reopened it,
 * from the data keys of the row; a row written before the keys existed keeps the generic text. The apps show the text as is.
 */
class NotificationTextTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const ULID = '01J9Z8Y0K1Q2R3S4T5V6W7X8Y9';

    /** @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string, 3: string}> */
    public static function rows(): array
    {
        $customer = Customer::class;
        $user = User::class;
        $invoice = ['invoice_id' => self::ULID, 'invoice_number' => 'INV-5', 'total' => '250000.00', 'outstanding' => '250000.00', 'due_date' => '2026-10-21'];
        $ticket = ['ticket_id' => self::ULID, 'ticket_code' => 'TKT-7'];

        return [
            'invoice issued' => [$customer, ['type' => 'invoice_issued', ...$invoice], 'Tagihan INV-5', 'Tagihan INV-5 sebesar Rp 250.000, jatuh tempo 21 Okt 2026.'],
            'invoice issued, old row' => [$customer, ['type' => 'invoice_issued', 'invoice_id' => self::ULID], 'Tagihan baru', 'Tagihan baru sudah terbit. Buka notifikasi ini untuk melihat rinciannya.'],
            'reminder H-3' => [$customer, ['type' => 'invoice_due_soon', 'stage' => 'H-3', ...$invoice], 'INV-5 jatuh tempo 3 hari lagi', 'Sisa Rp 250.000. Bayar lewat aplikasi.'],
            'reminder H-1' => [$customer, ['type' => 'invoice_due_soon', 'stage' => 'H-1', ...$invoice], 'INV-5 jatuh tempo besok', 'Sisa Rp 250.000. Bayar lewat aplikasi.'],
            'reminder sent by hand' => [$customer, ['type' => 'invoice_due_soon', 'stage' => 'manual', ...$invoice], 'INV-5 jatuh tempo 21 Okt 2026', 'Sisa Rp 250.000. Bayar lewat aplikasi.'],
            'reminder, old row' => [$customer, ['type' => 'invoice_due_soon', 'stage' => 'H-1'], 'Tagihan segera jatuh tempo', 'Tagihan Anda jatuh tempo besok. Buka notifikasi ini untuk melihat rinciannya.'],
            'overdue H+1' => [$customer, ['type' => 'invoice_overdue', 'stage' => 'H+1', ...$invoice], 'INV-5 lewat jatuh tempo', 'Sisa Rp 250.000 belum dibayar sejak 21 Okt 2026.'],
            'overdue, old row' => [$customer, ['type' => 'invoice_overdue', 'stage' => 'H+1'], 'Tagihan sudah jatuh tempo', 'Tagihan Anda sudah lewat jatuh tempo. Segera lakukan pembayaran.'],
            'payment received' => [$customer, ['type' => 'payment_received', 'invoice_id' => self::ULID, 'invoice_number' => 'INV-5', 'amount' => '100000.00'], 'Pembayaran diterima', 'Rp 100.000 untuk INV-5 sudah kami terima. Terima kasih.'],
            'payment received while a bill still holds the service' => [$customer, ['type' => 'payment_received', 'invoice_id' => self::ULID, 'invoice_number' => 'INV-5', 'amount' => '100000.00', 'holding_invoice_numbers' => ['INV-6']], 'Pembayaran diterima', 'Rp 100.000 untuk INV-5 sudah kami terima. Layanan aktif kembali setelah INV-6 dilunasi.'],
            'payment received, old row' => [$customer, ['type' => 'payment_received', 'invoice_id' => self::ULID], 'Pembayaran diterima', 'Pembayaran Anda sudah kami terima.'],
            'ticket assigned to staff' => [$user, ['type' => 'ticket_assigned', ...$ticket, 'priority' => 'high', 'title' => 'Router mati', 'response_due_local' => '08.30 besok'], 'Tiket TKT-7 · Tinggi', 'Router mati. Respons sebelum 08.30 besok.'],
            'ticket assigned to staff, no deadline' => [$user, ['type' => 'ticket_assigned', ...$ticket, 'priority' => 'urgent', 'title' => 'Router mati'], 'Tiket TKT-7 · Mendesak', 'Router mati.'],
            'ticket assigned to staff, old row' => [$user, ['type' => 'ticket_assigned', ...$ticket], 'Tiket ditugaskan', 'Tiket TKT-7 sudah ditugaskan kepada Anda.'],
            'ticket assigned to a customer keeps its text' => [$customer, ['type' => 'ticket_assigned', ...$ticket, 'priority' => 'high', 'title' => 'Router mati'], 'Petugas ditugaskan', 'Petugas sudah ditugaskan untuk tiket TKT-7.'],
            'ticket resolved' => [$customer, ['type' => 'ticket_resolved', ...$ticket, 'resolution_excerpt' => 'Kabel sudah diganti.'], 'Tiket TKT-7 diselesaikan', 'Kabel sudah diganti. Sudah beres?'],
            'ticket resolved, old row' => [$customer, ['type' => 'ticket_resolved', ...$ticket], 'Tiket selesai', 'Tiket TKT-7 sudah diselesaikan. Masih bermasalah? Balas tiket ini.'],
            'ticket reopened by staff' => [$user, ['type' => 'ticket_reopened', ...$ticket, 'by' => 'staff', 'actor_name' => 'Rina', 'reason' => 'Masih mati'], 'Tiket TKT-7 dibuka kembali', 'Oleh Rina: Masih mati'],
            'ticket reopened by staff, no reason' => [$user, ['type' => 'ticket_reopened', ...$ticket, 'by' => 'staff', 'actor_name' => 'Rina'], 'Tiket TKT-7 dibuka kembali', 'Oleh Rina.'],
            'ticket reopened by the customer reply' => [$user, ['type' => 'ticket_reopened', ...$ticket, 'by' => 'customer'], 'Tiket dibuka kembali', 'Pelanggan membalas tiket TKT-7 yang sudah diselesaikan, jadi tiket dibuka kembali.'],
            'ticket reopened, told to the customer' => [$customer, ['type' => 'ticket_reopened', ...$ticket], 'Tiket dibuka kembali', 'Tiket TKT-7 dibuka kembali dan akan ditangani lagi.'],
            'document expiring' => [$user, ['type' => 'customer_document_expiring', 'customer_id' => self::ULID, 'customer_code' => 'CUS-1', 'customer_name' => 'Asep Saepudin', 'document_type' => 'KTP', 'expires_at' => '2026-10-21'], 'Dokumen KTP Asep Saepudin segera habis', 'Berlaku sampai 21 Okt 2026.'],
            'document expiring, old shape' => [$user, ['type' => 'customer_document_expiring'], 'Dokumen pelanggan segera habis', 'Periksa dokumen pelanggan yang akan kedaluwarsa.'],
        ];
    }

    /**
     * @param  class-string  $notifiable
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('rows')]
    public function test_a_row_renders_the_words_of_its_keys(string $notifiable, array $data, string $title, string $message): void
    {
        $row = new DatabaseNotification(['id' => (string) Str::uuid(), 'notifiable_type' => $notifiable, 'data' => $data]);

        $bell = (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications'));

        $this->assertSame($title, $bell['title']);
        $this->assertSame($message, $bell['message']);
        $this->assertContains($data['type'], NotificationResource::TYPES);
    }

    public function test_the_invoice_bell_row_carries_the_number_the_amounts_and_the_due_date(): void
    {
        $admin = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id, 'status' => 'sent',
            'total' => 250000, 'paid_amount' => 100000, 'due_date' => '2026-10-21',
        ]);

        $data = (new InvoiceIssuedNotification($admin->company_id, $invoice->id))->toArray($customer);

        $this->assertSame('invoice_issued', $data['type']);
        $this->assertSame($invoice->public_id, $data['invoice_id']);
        $this->assertSame($invoice->number, $data['invoice_number']);
        $this->assertEquals(250000, $data['total']);
        $this->assertEquals(150000, $data['outstanding']);
        $this->assertSame('2026-10-21', $data['due_date']);
        $this->assertSame(['type', 'invoice_id', 'invoice_number', 'total', 'outstanding', 'due_date'], array_keys($data), 'ids, number, money and date only: no customer name, phone or address');
    }

    public function test_the_ticket_assigned_bell_row_carries_priority_title_and_the_deadline_on_the_company_clock(): void
    {
        $admin = $this->createCompanyUser();
        Company::query()->whereKey($admin->company_id)->update(['timezone' => 'Asia/Jakarta']);
        $this->travelTo('2026-06-01 02:00:00'); // 09:00 in Jakarta
        $ticket = Ticket::factory()->create([
            'company_id' => $admin->company_id, 'created_by' => $admin->id, 'priority' => 'high', 'title' => 'Router mati',
            'response_deadline' => '2026-06-01 06:30:00',
        ]);
        $notification = new TicketAssignedNotification($ticket->id, $ticket->code, $admin->company_id);

        $this->assertSame(
            ['type' => 'ticket_assigned', 'ticket_id' => $ticket->public_id, 'ticket_code' => $ticket->code, 'company_id' => $admin->company_id, 'priority' => 'high', 'title' => 'Router mati', 'response_due_local' => '13.30'],
            $notification->toArray($admin),
        );

        $ticket->update(['response_deadline' => '2026-06-02 01:30:00']);
        $this->assertSame('08.30 besok', $notification->toArray($admin)['response_due_local']);

        $ticket->update(['response_deadline' => null]);
        $this->assertArrayNotHasKey('response_due_local', $notification->toArray($admin));
    }
}
