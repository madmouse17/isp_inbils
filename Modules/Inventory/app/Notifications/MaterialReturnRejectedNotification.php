<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToReturnPage;

class MaterialReturnRejectedNotification extends Notification implements ShouldQueue
{
    use LinksToReturnPage;
    use Queueable;

    public function __construct(
        public readonly int $caseId,
        public readonly string $reason,
        public readonly ?int $companyId = null,
        public readonly ?int $inventoryRequestId = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Penyerahan Pengembalian Material Ditolak: Kasus #'.$this->caseId)
            ->line('Gudang menolak penyerahan fisik pengembalian material Anda.')
            ->line('Nomor Kasus Pengembalian: #'.$this->caseId)
            ->line('Alasan Penolakan: '.$this->reason)
            ->line('Periksa kembali barang fisik atau perbaiki deklarasi pengembalian Anda.')
            ->action('Lihat Kasus Pengembalian', $this->returnPageUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_return_rejected',
            'case_id' => $this->caseId,
            'reason' => $this->reason,
            'company_id' => $this->companyId,
            'inventory_request_id' => $this->inventoryRequestId,
        ];
    }
}
