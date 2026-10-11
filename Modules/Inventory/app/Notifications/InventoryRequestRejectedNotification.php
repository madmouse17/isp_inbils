<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $requestCode,
        public readonly string $reason,
        public readonly ?int $companyId = null,
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
            ->subject('Permintaan Barang Ditolak: '.$this->requestCode)
            ->line('Permintaan barang Anda telah ditolak oleh petugas gudang.')
            ->line('Kode Permintaan: '.$this->requestCode)
            ->line('Alasan Penolakan: '.$this->reason)
            ->action('Lihat Detail', url('/admin/inventory/requests/'.$this->requestId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_rejected',
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'reason' => $this->reason,
            'company_id' => $this->companyId,
        ];
    }
}
