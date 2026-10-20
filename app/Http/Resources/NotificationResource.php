<?php

namespace App\Http\Resources;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Every `type` a notification row can carry: what the apps may switch on (the OpenAPI document lists the same names).
     *
     * @var list<string>
     */
    public const TYPES = [
        'invoice_issued', 'invoice_reminder', 'invoice_due_soon', 'invoice_overdue', 'payment_received',
        'ticket_created', 'ticket_assigned', 'ticket_started', 'ticket_replied', 'ticket_resolved', 'ticket_closed',
        'ticket_customer_replied', 'ticket_reopened', 'ticket_sla_breached', 'ticket_first_response_due',
        'spk_scheduled', 'spk_scheduled_customer', 'spk_approved', 'spk_rejected', 'spk_submitted', 'technician_en_route', 'visit_completed',
        'billing_schedule_failed', 'payment_attempt_exception', 'invoice_draft_pending',
        'subscription_isolated', 'subscription_reactivated', 'subscriptions_isolated_summary',
        'inventory_request_submitted', 'inventory_request_approved', 'inventory_request_ready', 'inventory_request_rejected',
        'material_return_declared', 'material_return_exception', 'material_return_rejected',
        'announcement_published', 'push_test',
        'unknown',
    ];

    /** The record a row opens on the web, by type. A summary names no record, only its page. */
    private const LINKS = [
        'ticket_created' => 'ticket',
        'ticket_assigned' => 'ticket',
        'ticket_started' => 'ticket',
        'ticket_replied' => 'ticket',
        'ticket_resolved' => 'ticket',
        'ticket_closed' => 'ticket',
        'ticket_customer_replied' => 'ticket',
        'ticket_reopened' => 'ticket',
        'ticket_sla_breached' => 'ticket',
        'ticket_first_response_due' => 'ticket',
        'spk_submitted' => 'work_order',
        'invoice_draft_pending' => 'invoice',
        'subscriptions_isolated_summary' => 'receivables',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->getAttribute('data');
        $data = is_array($data) ? $data : [];
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unknown';
        $ticketCode = is_string($data['ticket_code'] ?? null) ? $data['ticket_code'] : null;
        $customerNotification = $this->getAttribute('notifiable_type') === Customer::class;

        return [
            'id' => (string) $this->id,
            /**
             * What happened. The apps pick an icon and wording from it and open `subject`; an unrecognised value is shown
             * with `title` and `message` as they are. `invoice_reminder` is only on rows written before `invoice_due_soon`
             * and `invoice_overdue` replaced it.
             *
             * @var 'invoice_issued'|'invoice_reminder'|'invoice_due_soon'|'invoice_overdue'|'payment_received'|'ticket_created'|'ticket_assigned'|'ticket_started'|'ticket_replied'|'ticket_resolved'|'ticket_closed'|'ticket_customer_replied'|'ticket_reopened'|'ticket_sla_breached'|'ticket_first_response_due'|'spk_scheduled'|'spk_scheduled_customer'|'spk_approved'|'spk_rejected'|'spk_submitted'|'technician_en_route'|'visit_completed'|'billing_schedule_failed'|'payment_attempt_exception'|'invoice_draft_pending'|'subscription_isolated'|'subscription_reactivated'|'subscriptions_isolated_summary'|'inventory_request_submitted'|'inventory_request_approved'|'inventory_request_ready'|'inventory_request_rejected'|'material_return_declared'|'material_return_exception'|'material_return_rejected'|'announcement_published'|'push_test'|'unknown'
             */
            'type' => $type,
            'title' => $this->title($type, $customerNotification, $data),
            'message' => $this->message($type, $ticketCode, $data, $customerNotification),
            'subject' => $this->subject($data),
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
            'detail_url' => $this->when(! $request->is('api/*'), fn () => $this->detailUrl($request, $type, $data)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function title(string $type, bool $customerNotification, array $data): string
    {
        return match ($type) {
            'invoice_issued' => 'Tagihan baru',
            'invoice_reminder' => 'Pengingat jatuh tempo',
            'invoice_due_soon' => 'Tagihan segera jatuh tempo',
            'invoice_overdue' => 'Tagihan sudah jatuh tempo',
            'payment_received' => 'Pembayaran diterima',
            'ticket_created' => $customerNotification ? 'Tiket dibuat' : 'Tiket baru',
            'ticket_assigned' => $customerNotification ? 'Petugas ditugaskan' : 'Tiket ditugaskan',
            'ticket_started' => 'Tiket mulai ditangani',
            'ticket_replied' => 'Balasan baru',
            'ticket_resolved' => 'Tiket selesai',
            'ticket_closed' => 'Tiket ditutup',
            'ticket_customer_replied' => 'Pelanggan membalas',
            'ticket_reopened' => 'Tiket dibuka kembali',
            'ticket_sla_breached' => ($data['kind'] ?? null) === 'response' ? 'Batas respons terlewat' : 'SLA tiket terlampaui',
            'ticket_first_response_due' => ($data['state'] ?? null) === 'breached' ? 'Batas respons pertama terlewat' : 'Respons pertama segera jatuh tempo',
            'spk_scheduled' => 'SPK baru ditugaskan',
            'spk_scheduled_customer' => 'Jadwal kunjungan teknisi',
            'spk_approved' => 'SPK disetujui',
            'spk_rejected' => 'SPK ditolak',
            'spk_submitted' => 'SPK menunggu review',
            'technician_en_route' => 'Teknisi dalam perjalanan',
            'visit_completed' => 'Kunjungan teknisi selesai',
            'billing_schedule_failed' => 'Jadwal penagihan gagal',
            'payment_attempt_exception' => 'Pembayaran online perlu dicek',
            'invoice_draft_pending' => 'Tagihan draf menunggu dikirim',
            'subscription_isolated' => ($data['cause'] ?? null) === 'overdue' ? 'Layanan diisolir' : 'Layanan dibatasi',
            'subscription_reactivated' => 'Layanan aktif kembali',
            'subscriptions_isolated_summary' => 'Isolir otomatis hari ini',
            'inventory_request_submitted' => 'Permintaan barang diajukan',
            'inventory_request_approved' => 'Permintaan barang disetujui',
            'inventory_request_ready' => 'Permintaan barang siap diambil',
            'inventory_request_rejected' => 'Permintaan barang ditolak',
            'material_return_declared' => 'Pengembalian material dideklarasikan',
            'material_return_exception' => 'Pengembalian material bermasalah',
            'material_return_rejected' => 'Pengembalian material ditolak',
            'announcement_published' => 'Pengumuman baru',
            'push_test' => 'Notifikasi uji',
            default => 'Notifikasi baru',
        };
    }

    /** @param array<string, mixed> $data */
    private function message(string $type, ?string $ticketCode, array $data, bool $customerNotification): string
    {
        $requestCode = is_string($data['request_code'] ?? null) ? $data['request_code'] : null;

        return match ($type) {
            'invoice_issued' => 'Tagihan baru sudah terbit. Buka notifikasi ini untuk melihat rinciannya.',
            'invoice_reminder' => 'Tagihan Anda perlu dibayar. Buka notifikasi ini untuk melihat rinciannya.',
            'invoice_due_soon' => ($data['stage'] ?? null) === 'H-1'
                ? 'Tagihan Anda jatuh tempo besok. Buka notifikasi ini untuk melihat rinciannya.'
                : 'Tagihan Anda jatuh tempo dalam 3 hari. Buka notifikasi ini untuk melihat rinciannya.',
            'invoice_overdue' => 'Tagihan Anda sudah lewat jatuh tempo. Segera lakukan pembayaran.',
            'payment_received' => $this->paymentReceivedMessage($data),
            'ticket_created' => $customerNotification
                ? ($ticketCode ? "Tiket {$ticketCode} sudah tercatat dan menunggu penanganan." : 'Tiket Anda sudah tercatat dan menunggu penanganan.')
                : $this->newTicketMessage($ticketCode, $data),
            'ticket_assigned' => $customerNotification
                ? ($ticketCode ? "Petugas sudah ditugaskan untuk tiket {$ticketCode}." : 'Petugas sudah ditugaskan untuk tiket Anda.')
                : ($ticketCode ? "Tiket {$ticketCode} sudah ditugaskan kepada Anda." : 'Sebuah tiket sudah ditugaskan kepada Anda.'),
            'ticket_started' => $ticketCode ? "Petugas mulai menangani tiket {$ticketCode}." : 'Petugas mulai menangani tiket Anda.',
            'ticket_replied' => $ticketCode ? "Petugas membalas tiket {$ticketCode}." : 'Petugas membalas tiket Anda.',
            'ticket_resolved' => ($ticketCode ? "Tiket {$ticketCode} sudah diselesaikan." : 'Tiket Anda sudah diselesaikan.').' Masih bermasalah? Balas tiket ini.',
            'ticket_closed' => $ticketCode ? "Tiket {$ticketCode} sudah ditutup." : 'Tiket Anda sudah ditutup.',
            'ticket_customer_replied' => $ticketCode ? "Pelanggan membalas tiket {$ticketCode}." : 'Pelanggan membalas sebuah tiket.',
            'ticket_reopened' => ($ticketCode ? "Pelanggan membalas tiket {$ticketCode} yang sudah diselesaikan" : 'Pelanggan membalas tiket yang sudah diselesaikan').', jadi tiket dibuka kembali.',
            'ticket_sla_breached' => ($data['kind'] ?? null) === 'response'
                ? ($ticketCode ? "Tiket {$ticketCode} belum direspons melewati batas SLA." : 'Sebuah tiket belum direspons melewati batas SLA.')
                : ($ticketCode ? "Tiket {$ticketCode} melewati batas penyelesaian SLA." : 'Sebuah tiket melewati batas penyelesaian SLA.'),
            'ticket_first_response_due' => ($data['state'] ?? null) === 'breached'
                ? ($ticketCode ? "Tiket {$ticketCode} melewati batas respons pertama." : 'Sebuah tiket melewati batas respons pertama.')
                : ($ticketCode ? "Tiket {$ticketCode} perlu direspons dalam 30 menit." : 'Sebuah tiket perlu direspons dalam 30 menit.'),
            'spk_scheduled' => 'SPK baru sudah ditugaskan kepada Anda.',
            'spk_scheduled_customer' => 'Kunjungan teknisi untuk layanan Anda sudah dijadwalkan.',
            'spk_approved' => is_string($data['work_order_code'] ?? null)
                ? "SPK {$data['work_order_code']} sudah disetujui."
                : 'SPK Anda sudah disetujui.',
            'spk_rejected' => (is_string($data['work_order_code'] ?? null) ? "SPK {$data['work_order_code']} ditolak." : 'SPK Anda ditolak.')
                .(is_string($data['reason'] ?? null) && $data['reason'] !== '' ? " Alasan: {$data['reason']}" : ' Buka SPK untuk melihat alasannya.'),
            'spk_submitted' => $this->submittedSpkMessage($data),
            'visit_completed' => 'Kunjungan teknisi untuk layanan Anda sudah selesai.',
            'technician_en_route' => is_string($data['technician_first_name'] ?? null)
                ? "Teknisi {$data['technician_first_name']} sedang menuju lokasi Anda."
                : 'Teknisi sedang menuju lokasi Anda.',
            'billing_schedule_failed' => 'Operasi penagihan terjadwal gagal. Periksa log aplikasi.',
            'payment_attempt_exception' => is_string($data['order_id'] ?? null) && is_string($data['invoice_number'] ?? null) && is_numeric($data['amount'] ?? null)
                ? "Pembayaran {$data['order_id']} sebesar Rp ".number_format((float) $data['amount'], 0, ',', '.')." untuk tagihan {$data['invoice_number']} tidak bisa dibukukan otomatis. Periksa di menu Tunggakan."
                : 'Sebuah pembayaran online tidak bisa dibukukan otomatis. Periksa di menu Tunggakan.',
            'invoice_draft_pending' => $this->draftInvoiceMessage($data),
            'subscription_isolated' => $this->isolatedSubscriptionMessage($data),
            'subscription_reactivated' => is_string($data['subscription_code'] ?? null)
                ? "Layanan {$data['subscription_code']} sudah aktif kembali."
                : 'Layanan Anda sudah aktif kembali.',
            'subscriptions_isolated_summary' => is_int($data['count'] ?? null)
                ? "{$data['count']} langganan diisolir otomatis karena tunggakan."
                : 'Beberapa langganan diisolir otomatis karena tunggakan.',
            'inventory_request_submitted' => $requestCode
                ? "Permintaan {$requestCode} menunggu tinjauan gudang."
                : 'Sebuah permintaan barang menunggu tinjauan gudang.',
            'inventory_request_approved' => $requestCode
                ? "Permintaan {$requestCode} disetujui dan stoknya sudah dicadangkan."
                : 'Sebuah permintaan barang disetujui dan stoknya sudah dicadangkan.',
            'inventory_request_ready' => $requestCode
                ? "Permintaan {$requestCode} siap diambil."
                : 'Sebuah permintaan barang siap diambil.',
            'inventory_request_rejected' => $requestCode
                ? "Permintaan {$requestCode} ditolak."
                : 'Sebuah permintaan barang ditolak.',
            'material_return_declared' => 'Pengembalian material sudah dideklarasikan dan menunggu penerimaan gudang.',
            'material_return_exception' => 'Pengembalian material memiliki selisih yang perlu diselesaikan.',
            'material_return_rejected' => 'Serah terima pengembalian material ditolak. Periksa kembali deklarasinya.',
            'announcement_published' => is_string($data['title'] ?? null)
                ? (string) $data['title']
                : 'Ada pengumuman baru untuk layanan Anda.',
            'push_test' => is_string($data['company_name'] ?? null)
                ? "Notifikasi uji dari {$data['company_name']}."
                : 'Notifikasi uji dari server.',
            default => 'Notifikasi ini tersedia di kotak masuk Anda.',
        };
    }

    /** @param array<string, mixed> $data */
    private function paymentReceivedMessage(array $data): string
    {
        $holding = $this->strings($data['holding_invoice_numbers'] ?? null);

        if ($holding === []) {
            return 'Pembayaran Anda sudah kami terima.';
        }

        $number = is_string($data['invoice_number'] ?? null) ? $data['invoice_number'] : null;

        return ($number === null ? 'Pembayaran Anda sudah kami terima.' : "Pembayaran {$number} sudah kami terima.")
            .' Layanan aktif kembali setelah '.implode(', ', $holding).' dilunasi.';
    }

    /** @param array<string, mixed> $data */
    private function newTicketMessage(?string $ticketCode, array $data): string
    {
        if ($ticketCode === null) {
            return 'Sebuah tiket baru menunggu penugasan.';
        }

        $customer = is_string($data['customer_name'] ?? null) && $data['customer_name'] !== '' ? " dari {$data['customer_name']}" : '';

        return "Tiket {$ticketCode}{$customer} menunggu penugasan.";
    }

    /** @param array<string, mixed> $data */
    private function submittedSpkMessage(array $data): string
    {
        if (! is_string($data['work_order_code'] ?? null)) {
            return 'Sebuah SPK menunggu persetujuan.';
        }

        $technician = is_string($data['technician_name'] ?? null) && $data['technician_name'] !== '' ? " dari {$data['technician_name']}" : '';

        return "{$data['work_order_code']}{$technician} menunggu persetujuan.";
    }

    /** @param array<string, mixed> $data */
    private function draftInvoiceMessage(array $data): string
    {
        if (! is_string($data['invoice_number'] ?? null)) {
            return 'Sebuah tagihan draf menunggu dikirim.';
        }

        $customer = is_string($data['customer_name'] ?? null) && $data['customer_name'] !== '' ? " untuk {$data['customer_name']}" : '';
        $total = $this->rupiah($data['total'] ?? null);

        return "Tagihan {$data['invoice_number']}{$customer}".($total === null ? '' : " sebesar {$total}").' menunggu dikirim.';
    }

    /** @param array<string, mixed> $data */
    private function isolatedSubscriptionMessage(array $data): string
    {
        $code = is_string($data['subscription_code'] ?? null) ? $data['subscription_code'] : null;
        $service = $code === null ? 'Layanan Anda' : "Layanan {$code}";

        if (($data['cause'] ?? null) !== 'overdue') {
            $isp = is_string($data['company_name'] ?? null) && $data['company_name'] !== '' ? " oleh {$data['company_name']}" : '';

            return "{$service} sedang dibatasi{$isp}. Hubungi kami untuk informasi.";
        }

        $invoices = $this->strings($data['invoice_numbers'] ?? null);
        $because = $invoices === [] ? 'ada tagihan' : 'tagihan '.implode(', ', $invoices);

        return "{$service} diisolir karena {$because} lewat jatuh tempo. Bayar agar layanan aktif kembali.";
    }

    /** "Rp 250.000"; cents only when the amount has some. */
    private function rupiah(mixed $amount): ?string
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return 'Rp '.number_format((float) $amount, fmod((float) $amount, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
    }

    /**
     * The record a notification is about, by public id. A stored value that is not a ULID (an integer id from a row
     * the D6 migration could not map) names nothing: an integer id never leaves the server.
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, id: string}|null
     */
    private function subject(array $data): ?array
    {
        foreach ([
            'ticket_id' => 'ticket',
            'work_order_id' => 'work_order',
            'invoice_id' => 'invoice',
            'request_id' => 'inventory_request',
            'case_id' => 'material_return',
            'announcement_id' => 'announcement',
            'subscription_id' => 'subscription',
        ] as $key => $type) {
            $id = $data[$key] ?? null;

            if (is_string($id) && Str::isUlid($id)) {
                return ['type' => $type, 'id' => strtolower($id)];
            }
        }

        return null;
    }

    /**
     * The page of the row on the web, for a row of the viewer's own company that names a record the viewer may open.
     *
     * @param  array<string, mixed>  $data
     */
    private function detailUrl(Request $request, string $type, array $data): ?string
    {
        $link = self::LINKS[$type] ?? null;
        $payloadCompanyId = $data['company_id'] ?? null;
        $user = $request->user();

        if ($link === null || $user === null) {
            return null;
        }
        if (! is_int($payloadCompanyId) && ! (is_string($payloadCompanyId) && ctype_digit($payloadCompanyId))) {
            return null;
        }

        $companyId = CompanyService::idFor($user);
        if ($companyId === null || (int) $payloadCompanyId !== $companyId) {
            return null;
        }

        return match ($link) {
            'ticket' => $this->recordUrl(Ticket::forCompany($companyId), $data['ticket_id'] ?? null, $user, 'admin.tickets.show', 'ticket'),
            'work_order' => $this->recordUrl(WorkOrder::forCompany($companyId), $data['work_order_id'] ?? null, $user, 'admin.spk.show', 'wo'),
            'invoice' => $this->recordUrl(Invoice::forCompany($companyId), $data['invoice_id'] ?? null, $user, 'admin.invoices.show', 'invoice'),
            default => $user->can('billing.view') ? route('admin.billing.receivables') : null,
        };
    }

    /**
     * The record of the viewer's company a public id names, as a route to its page, if the viewer may view it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $records  already limited to the viewer's company
     */
    private function recordUrl(Builder $records, mixed $publicId, User $user, string $route, string $parameter): ?string
    {
        if (! is_string($publicId) || ! Str::isUlid($publicId)) {
            return null;
        }

        $record = $records->where($records->getModel()->qualifyColumn('public_id'), $publicId)->first();

        return $record !== null && Gate::forUser($user)->allows('view', $record)
            ? route($route, [$parameter => $record])
            : null;
    }
}
