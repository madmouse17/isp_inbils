<?php

namespace App\Http\Resources;

use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
        'ticket_created', 'ticket_assigned', 'ticket_resolved', 'ticket_sla_breached', 'ticket_first_response_due',
        'spk_scheduled', 'spk_scheduled_customer', 'spk_approved', 'spk_rejected', 'technician_en_route', 'visit_completed',
        'billing_schedule_failed', 'payment_attempt_exception',
        'inventory_request_submitted', 'inventory_request_approved', 'inventory_request_ready', 'inventory_request_rejected',
        'material_return_declared', 'material_return_exception', 'material_return_rejected',
        'announcement_published', 'push_test',
        'unknown',
    ];

    /** @var list<string> */
    private const LINKABLE_TYPES = ['ticket_assigned', 'ticket_sla_breached', 'ticket_first_response_due'];

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
             * @var 'invoice_issued'|'invoice_reminder'|'invoice_due_soon'|'invoice_overdue'|'payment_received'|'ticket_created'|'ticket_assigned'|'ticket_resolved'|'ticket_sla_breached'|'ticket_first_response_due'|'spk_scheduled'|'spk_scheduled_customer'|'spk_approved'|'spk_rejected'|'technician_en_route'|'visit_completed'|'billing_schedule_failed'|'payment_attempt_exception'|'inventory_request_submitted'|'inventory_request_approved'|'inventory_request_ready'|'inventory_request_rejected'|'material_return_declared'|'material_return_exception'|'material_return_rejected'|'announcement_published'|'push_test'|'unknown'
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
            'ticket_created' => 'Tiket dibuat',
            'ticket_assigned' => $customerNotification ? 'Tiket mulai ditangani' : 'Tiket ditugaskan',
            'ticket_resolved' => 'Tiket selesai',
            'ticket_sla_breached' => 'SLA tiket terlampaui',
            'ticket_first_response_due' => ($data['state'] ?? null) === 'breached' ? 'Batas respons pertama terlewat' : 'Respons pertama segera jatuh tempo',
            'spk_scheduled' => 'SPK baru ditugaskan',
            'spk_scheduled_customer' => 'Jadwal kunjungan teknisi',
            'spk_approved' => 'SPK disetujui',
            'spk_rejected' => 'SPK ditolak',
            'technician_en_route' => 'Teknisi dalam perjalanan',
            'visit_completed' => 'Kunjungan teknisi selesai',
            'billing_schedule_failed' => 'Jadwal penagihan gagal',
            'payment_attempt_exception' => 'Pembayaran online perlu dicek',
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
            'payment_received' => 'Pembayaran Anda sudah kami terima.',
            'ticket_created' => 'Tiket Anda sudah tercatat dan menunggu penanganan.',
            'ticket_assigned' => $customerNotification
                ? 'Tiket Anda sudah mulai ditangani petugas kami.'
                : ($ticketCode ? "Tiket {$ticketCode} sudah ditugaskan kepada Anda." : 'Sebuah tiket sudah ditugaskan kepada Anda.'),
            'ticket_resolved' => 'Tiket Anda sudah diselesaikan.',
            'ticket_sla_breached' => $ticketCode
                ? "Tiket {$ticketCode} melewati batas SLA."
                : 'Sebuah tiket melewati batas SLA.',
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
            'visit_completed' => 'Kunjungan teknisi untuk layanan Anda sudah selesai.',
            'technician_en_route' => is_string($data['technician_first_name'] ?? null)
                ? "Teknisi {$data['technician_first_name']} sedang menuju lokasi Anda."
                : 'Teknisi sedang menuju lokasi Anda.',
            'billing_schedule_failed' => 'Operasi penagihan terjadwal gagal. Periksa log aplikasi.',
            'payment_attempt_exception' => is_string($data['order_id'] ?? null) && is_string($data['invoice_number'] ?? null) && is_numeric($data['amount'] ?? null)
                ? "Pembayaran {$data['order_id']} sebesar Rp ".number_format((float) $data['amount'], 0, ',', '.')." untuk tagihan {$data['invoice_number']} tidak bisa dibukukan otomatis. Periksa di menu Tunggakan."
                : 'Sebuah pembayaran online tidak bisa dibukukan otomatis. Periksa di menu Tunggakan.',
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
        ] as $key => $type) {
            $id = $data[$key] ?? null;

            if (is_string($id) && Str::isUlid($id)) {
                return ['type' => $type, 'id' => strtolower($id)];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function detailUrl(Request $request, string $type, array $data): ?string
    {
        if (! in_array($type, self::LINKABLE_TYPES, true)) {
            return null;
        }

        $ticketId = $data['ticket_id'] ?? null;
        $payloadCompanyId = $data['company_id'] ?? null;
        $user = $request->user();
        if (! is_string($ticketId) || ! Str::isUlid($ticketId)) {
            return null;
        }
        if (! is_int($payloadCompanyId) && ! (is_string($payloadCompanyId) && ctype_digit($payloadCompanyId))) {
            return null;
        }
        $companyId = $user === null ? null : CompanyService::idFor($user);
        if ($companyId === null || (int) $payloadCompanyId !== $companyId) {
            return null;
        }

        $ticket = Ticket::withoutCompany()
            ->where('company_id', $companyId)
            ->wherePublicId($ticketId)
            ->first();

        if ($ticket === null || ! Gate::forUser($user)->allows('view', $ticket)) {
            return null;
        }

        return route('admin.tickets.show', ['ticket' => $ticket]);
    }
}
