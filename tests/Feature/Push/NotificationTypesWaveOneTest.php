<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * Every type that wave 1 adds or rewords, as the bell and the push of an app render it: title, message and subject, for the
 * staff and the customer variant, and the link a web row opens only for a viewer who may open its record.
 */
class NotificationTypesWaveOneTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const TICKET = '01J9Z8Y0K1Q2R3S4T5V6W7X8Y9';

    /** @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string, 3: string, 4: ?array{0: string, 1: string}}> */
    public static function rows(): array
    {
        $user = User::class;
        $customer = Customer::class;
        $ticket = ['ticket_id' => self::TICKET, 'ticket_code' => 'TKT-7'];

        return [
            'staff: a new ticket names the customer' => [$user, ['type' => 'ticket_created', ...$ticket, 'customer_name' => 'Asep Saepudin'], 'Tiket baru', 'Tiket TKT-7 dari Asep Saepudin menunggu penugasan.', ['ticket', self::TICKET]],
            'staff: a new ticket without a customer' => [$user, ['type' => 'ticket_created', ...$ticket, 'customer_name' => null], 'Tiket baru', 'Tiket TKT-7 menunggu penugasan.', ['ticket', self::TICKET]],
            'customer: ticket registered' => [$customer, ['type' => 'ticket_created', ...$ticket], 'Tiket dibuat', 'Tiket TKT-7 sudah tercatat dan menunggu penanganan.', ['ticket', self::TICKET]],
            'customer: a handler is assigned' => [$customer, ['type' => 'ticket_assigned', ...$ticket], 'Petugas ditugaskan', 'Petugas sudah ditugaskan untuk tiket TKT-7.', ['ticket', self::TICKET]],
            'staff: a ticket is assigned to me' => [$user, ['type' => 'ticket_assigned', ...$ticket], 'Tiket ditugaskan', 'Tiket TKT-7 sudah ditugaskan kepada Anda.', ['ticket', self::TICKET]],
            'customer: work started' => [$customer, ['type' => 'ticket_started', ...$ticket], 'Tiket mulai ditangani', 'Petugas mulai menangani tiket TKT-7.', ['ticket', self::TICKET]],
            'customer: staff replied' => [$customer, ['type' => 'ticket_replied', ...$ticket], 'Balasan baru', 'Petugas membalas tiket TKT-7.', ['ticket', self::TICKET]],
            'customer: resolved' => [$customer, ['type' => 'ticket_resolved', ...$ticket], 'Tiket selesai', 'Tiket TKT-7 sudah diselesaikan. Masih bermasalah? Balas tiket ini.', ['ticket', self::TICKET]],
            'customer: closed' => [$customer, ['type' => 'ticket_closed', ...$ticket], 'Tiket ditutup', 'Tiket TKT-7 sudah ditutup.', ['ticket', self::TICKET]],
            'staff: the customer replied' => [$user, ['type' => 'ticket_customer_replied', ...$ticket], 'Pelanggan membalas', 'Pelanggan membalas tiket TKT-7.', ['ticket', self::TICKET]],
            'staff: the customer reopened it' => [$user, ['type' => 'ticket_reopened', ...$ticket], 'Tiket dibuka kembali', 'Pelanggan membalas tiket TKT-7 yang sudah diselesaikan, jadi tiket dibuka kembali.', ['ticket', self::TICKET]],
            'staff: response target missed' => [$user, ['type' => 'ticket_sla_breached', ...$ticket, 'kind' => 'response'], 'Batas respons terlewat', 'Tiket TKT-7 belum direspons melewati batas SLA.', ['ticket', self::TICKET]],
            'staff: resolution target missed' => [$user, ['type' => 'ticket_sla_breached', ...$ticket, 'kind' => 'resolution'], 'SLA tiket terlampaui', 'Tiket TKT-7 melewati batas penyelesaian SLA.', ['ticket', self::TICKET]],
            'staff: a breach row written before the kind existed' => [$user, ['type' => 'ticket_sla_breached', ...$ticket, 'breach_type' => 'resolution'], 'SLA tiket terlampaui', 'Tiket TKT-7 melewati batas penyelesaian SLA.', ['ticket', self::TICKET]],
            'staff: response due soon' => [$user, ['type' => 'ticket_first_response_due', ...$ticket], 'Respons pertama segera jatuh tempo', 'Tiket TKT-7 perlu direspons dalam 30 menit.', ['ticket', self::TICKET]],
            'staff: a due row written before breaches had their own type' => [$user, ['type' => 'ticket_first_response_due', ...$ticket, 'state' => 'breached'], 'Batas respons pertama terlewat', 'Tiket TKT-7 melewati batas respons pertama.', ['ticket', self::TICKET]],
            'customer: a notice without the code still reads' => [$customer, ['type' => 'ticket_started'], 'Tiket mulai ditangani', 'Petugas mulai menangani tiket Anda.', null],
            'staff: an spk waits for review' => [$user, ['type' => 'spk_submitted', 'work_order_id' => self::TICKET, 'work_order_code' => 'SPK-9', 'technician_name' => 'Budi Santoso'], 'SPK menunggu review', 'SPK-9 dari Budi Santoso menunggu persetujuan.', ['work_order', self::TICKET]],
            'staff: a draft invoice waits to be sent' => [$user, ['type' => 'invoice_draft_pending', 'invoice_id' => self::TICKET, 'invoice_number' => 'INV-5', 'customer_name' => 'Asep Saepudin', 'total' => '250000.00'], 'Tagihan draf menunggu dikirim', 'Tagihan INV-5 untuk Asep Saepudin sebesar Rp 250.000 menunggu dikirim.', ['invoice', self::TICKET]],
            'customer: payment received while invoices still hold' => [$customer, ['type' => 'payment_received', 'invoice_id' => self::TICKET, 'invoice_number' => 'INV-5', 'holding_invoice_numbers' => ['INV-6', 'INV-7']], 'Pembayaran diterima', 'Pembayaran INV-5 sudah kami terima. Layanan aktif kembali setelah INV-6, INV-7 dilunasi.', ['invoice', self::TICKET]],
            'customer: payment received, nothing holds' => [$customer, ['type' => 'payment_received', 'invoice_id' => self::TICKET, 'holding_invoice_numbers' => []], 'Pembayaran diterima', 'Pembayaran Anda sudah kami terima.', ['invoice', self::TICKET]],
            'customer: isolated for an overdue invoice' => [$customer, ['type' => 'subscription_isolated', 'subscription_id' => self::TICKET, 'subscription_code' => 'SUB-3', 'cause' => 'overdue', 'invoice_numbers' => ['INV-8']], 'Layanan diisolir', 'Layanan SUB-3 diisolir karena tagihan INV-8 lewat jatuh tempo. Bayar agar layanan aktif kembali.', ['subscription', self::TICKET]],
            'customer: suspended by hand' => [$customer, ['type' => 'subscription_isolated', 'subscription_id' => self::TICKET, 'subscription_code' => 'SUB-3', 'cause' => 'manual', 'company_name' => 'PT Contoh Net'], 'Layanan dibatasi', 'Layanan SUB-3 sedang dibatasi oleh PT Contoh Net. Hubungi kami untuk informasi.', ['subscription', self::TICKET]],
            'customer: blocked' => [$customer, ['type' => 'subscription_isolated', 'subscription_id' => self::TICKET, 'subscription_code' => 'SUB-3', 'cause' => 'blocked', 'company_name' => 'PT Contoh Net'], 'Layanan dibatasi', 'Layanan SUB-3 sedang dibatasi oleh PT Contoh Net. Hubungi kami untuk informasi.', ['subscription', self::TICKET]],
            'customer: active again' => [$customer, ['type' => 'subscription_reactivated', 'subscription_id' => self::TICKET, 'subscription_code' => 'SUB-3'], 'Layanan aktif kembali', 'Layanan SUB-3 sudah aktif kembali.', ['subscription', self::TICKET]],
            'staff: the daily isolation summary' => [$user, ['type' => 'subscriptions_isolated_summary', 'count' => 3], 'Isolir otomatis hari ini', '3 langganan diisolir otomatis karena tunggakan.', null],
        ];
    }

    /**
     * @param  class-string  $notifiable
     * @param  array<string, mixed>  $data
     * @param  array{0: string, 1: string}|null  $subject
     */
    #[DataProvider('rows')]
    public function test_a_row_renders_its_title_message_and_subject(string $notifiable, array $data, string $title, string $message, ?array $subject): void
    {
        $bell = $this->render($notifiable, $data, Request::create('/api/v1/notifications'));

        $this->assertSame($data['type'], $bell['type']);
        $this->assertSame($title, $bell['title']);
        $this->assertSame($message, $bell['message']);
        $this->assertSame($subject === null ? null : ['type' => $subject[0], 'id' => strtolower($subject[1])], $bell['subject']);
        $this->assertContains($data['type'], NotificationResource::TYPES);
    }

    public function test_every_type_wave_one_adds_is_listed_for_the_apps(): void
    {
        foreach ([
            'ticket_started', 'ticket_replied', 'ticket_closed', 'ticket_customer_replied', 'ticket_reopened', 'spk_submitted',
            'invoice_draft_pending', 'subscription_isolated', 'subscription_reactivated', 'subscriptions_isolated_summary',
        ] as $type) {
            $this->assertContains($type, NotificationResource::TYPES);
        }
    }

    public function test_ticket_rows_link_to_the_ticket_only_for_a_viewer_of_the_same_company(): void
    {
        $admin = $this->createCompanyUser();
        $ticket = Ticket::factory()->create(['company_id' => $admin->company_id, 'created_by' => $admin->id]);
        $sameCompanyWithoutRights = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $otherCompany = Company::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $stranger->givePermissionTo('ticket.view');

        foreach (['ticket_created', 'ticket_started', 'ticket_replied', 'ticket_closed', 'ticket_customer_replied', 'ticket_reopened', 'ticket_sla_breached', 'ticket_first_response_due'] as $type) {
            $row = ['type' => $type, 'ticket_id' => $ticket->public_id, 'ticket_code' => $ticket->code, 'company_id' => $admin->company_id];

            $this->assertSame(route('admin.tickets.show', $ticket), $this->link(User::class, $row, $admin), $type);
            $this->assertNull($this->link(User::class, $row, $sameCompanyWithoutRights), $type);
            $this->assertNull($this->link(User::class, $row, $stranger), $type);
        }
        $this->assertNull($this->link(User::class, ['type' => 'ticket_created', 'ticket_id' => $ticket->public_id, 'company_id' => $otherCompany->id], $admin), 'a row of another company opens nothing');
    }

    public function test_an_spk_waiting_for_review_links_to_the_spk_for_a_viewer(): void
    {
        $admin = $this->createCompanyUser();
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $admin->company_id, 'status' => 'waiting_review']);
        $without = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $row = ['type' => 'spk_submitted', 'work_order_id' => $workOrder->public_id, 'work_order_code' => $workOrder->code, 'company_id' => $admin->company_id];

        $this->assertSame(route('admin.spk.show', $workOrder), $this->link(User::class, $row, $admin));
        $this->assertNull($this->link(User::class, $row, $without));
        $this->assertNull($this->link(User::class, ['work_order_id' => PublicId::new()] + $row, $admin), 'an SPK that is gone opens nothing');
    }

    public function test_a_draft_invoice_links_to_the_invoice_for_a_viewer(): void
    {
        $admin = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $invoice = InvoiceFactory::new()->create(['company_id' => $admin->company_id, 'customer_id' => $customer->id, 'status' => 'draft', 'total' => 250000]);
        $without = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $row = ['type' => 'invoice_draft_pending', 'invoice_id' => $invoice->public_id, 'invoice_number' => $invoice->number, 'company_id' => $admin->company_id];

        $this->assertSame(route('admin.invoices.show', $invoice), $this->link(User::class, $row, $admin));
        $this->assertNull($this->link(User::class, $row, $without));
    }

    public function test_the_isolation_summary_links_to_receivables_for_billing_viewers_of_its_company(): void
    {
        $admin = $this->createCompanyUser();
        $without = User::factory()->create(['company_id' => $admin->company_id, 'is_active' => true]);
        $row = ['type' => 'subscriptions_isolated_summary', 'count' => 2, 'company_id' => $admin->company_id];

        $this->assertSame(route('admin.billing.receivables'), $this->link(User::class, $row, $admin));
        $this->assertNull($this->link(User::class, $row, $without));
        $this->assertNull($this->link(User::class, ['company_id' => Company::factory()->create()->id] + $row, $admin));
        $this->assertNull($this->link(User::class, ['company_id' => null] + $row, $admin));
    }

    /**
     * @param  class-string  $notifiable
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function render(string $notifiable, array $data, Request $request): array
    {
        $row = new DatabaseNotification(['id' => (string) Str::uuid(), 'notifiable_type' => $notifiable, 'data' => $data]);

        return (new NotificationResource($row))->resolve($request);
    }

    /**
     * The link of a row on the web, for the user looking at it.
     *
     * @param  class-string  $notifiable
     * @param  array<string, mixed>  $data
     */
    private function link(string $notifiable, array $data, User $viewer): ?string
    {
        $request = Request::create('/admin/notifications');
        $request->setUserResolver(fn () => $viewer);

        return $this->render($notifiable, $data, $request)['detail_url'];
    }
}
