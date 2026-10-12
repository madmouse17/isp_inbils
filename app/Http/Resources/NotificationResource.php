<?php

namespace App\Http\Resources;

use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Models\Ticket;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @var list<string> */
    private const LINKABLE_TYPES = ['ticket_assigned', 'ticket_sla_breached'];

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
            'type' => $type,
            'title' => $this->title($type, $customerNotification),
            'message' => $this->message($type, $ticketCode, $data, $customerNotification),
            'subject' => $this->subject($data),
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
            'detail_url' => $this->when(! $request->is('api/*'), fn () => $this->detailUrl($request, $type, $data)),
        ];
    }

    private function title(string $type, bool $customerNotification): string
    {
        return match ($type) {
            'invoice_issued' => 'Tagihan baru',
            'invoice_reminder' => 'Pengingat jatuh tempo',
            'payment_received' => 'Pembayaran diterima',
            'ticket_created' => 'Tiket dibuat',
            'ticket_assigned' => $customerNotification ? 'Tiket mulai ditangani' : 'Tiket ditugaskan',
            'ticket_resolved' => 'Tiket selesai',
            'ticket_sla_breached' => 'SLA tiket terlampaui',
            'spk_scheduled' => 'SPK baru ditugaskan',
            'spk_scheduled_customer' => 'Jadwal kunjungan teknisi',
            'billing_schedule_failed' => 'Jadwal penagihan gagal',
            'inventory_request_submitted' => 'Permintaan barang diajukan',
            'inventory_request_approved' => 'Permintaan barang disetujui',
            'inventory_request_ready' => 'Permintaan barang siap diambil',
            'inventory_request_rejected' => 'Permintaan barang ditolak',
            'material_return_declared' => 'Pengembalian material dideklarasikan',
            'material_return_exception' => 'Pengembalian material bermasalah',
            'material_return_rejected' => 'Pengembalian material ditolak',
            default => 'Notifikasi baru',
        };
    }

    /** @param array<string, mixed> $data */
    private function message(string $type, ?string $ticketCode, array $data, bool $customerNotification): string
    {
        $requestCode = is_string($data['request_code'] ?? null) ? $data['request_code'] : null;
        $caseId = $data['case_id'] ?? null;
        $caseId = is_int($caseId) || (is_string($caseId) && ctype_digit($caseId)) ? (int) $caseId : null;

        return match ($type) {
            'invoice_issued' => 'Tagihan baru sudah terbit. Buka notifikasi ini untuk melihat rinciannya.',
            'invoice_reminder' => 'Tagihan Anda perlu dibayar. Buka notifikasi ini untuk melihat rinciannya.',
            'payment_received' => 'Pembayaran Anda sudah kami terima.',
            'ticket_created' => 'Tiket Anda sudah tercatat dan menunggu penanganan.',
            'ticket_assigned' => $customerNotification
                ? 'Tiket Anda sudah mulai ditangani petugas kami.'
                : ($ticketCode ? "Tiket {$ticketCode} sudah ditugaskan kepada Anda." : 'Sebuah tiket sudah ditugaskan kepada Anda.'),
            'ticket_resolved' => 'Tiket Anda sudah diselesaikan.',
            'ticket_sla_breached' => $ticketCode
                ? "Tiket {$ticketCode} melewati batas SLA."
                : 'Sebuah tiket melewati batas SLA.',
            'spk_scheduled' => 'SPK baru sudah ditugaskan kepada Anda.',
            'spk_scheduled_customer' => 'Kunjungan teknisi untuk layanan Anda sudah dijadwalkan.',
            'billing_schedule_failed' => 'Operasi penagihan terjadwal gagal. Periksa log aplikasi.',
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
            'material_return_declared' => $caseId !== null
                ? "Kasus pengembalian #{$caseId} sudah dideklarasikan dan menunggu penerimaan gudang."
                : 'Pengembalian material sudah dideklarasikan dan menunggu penerimaan gudang.',
            'material_return_exception' => $caseId !== null
                ? "Kasus pengembalian #{$caseId} memiliki selisih yang perlu diselesaikan."
                : 'Pengembalian material memiliki selisih yang perlu diselesaikan.',
            'material_return_rejected' => $caseId !== null
                ? "Serah terima kasus pengembalian #{$caseId} ditolak. Periksa kembali deklarasinya."
                : 'Serah terima pengembalian material ditolak. Periksa kembali deklarasinya.',
            default => 'Notifikasi ini tersedia di kotak masuk Anda.',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, id: int}|null
     */
    private function subject(array $data): ?array
    {
        foreach ([
            'ticket_id' => 'ticket',
            'work_order_id' => 'work_order',
            'invoice_id' => 'invoice',
            'request_id' => 'inventory_request',
            'case_id' => 'material_return',
        ] as $key => $type) {
            $id = $data[$key] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return ['type' => $type, 'id' => (int) $id];
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
        if (! is_int($ticketId) && ! (is_string($ticketId) && ctype_digit($ticketId))) {
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
            ->find((int) $ticketId);

        if ($ticket === null || ! Gate::forUser($user)->allows('view', $ticket)) {
            return null;
        }

        return route('admin.tickets.show', ['ticket' => $ticket]);
    }
}
