<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $requestCode,
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
            ->subject('Permintaan Barang Baru: '.$this->requestCode)
            ->line('Ada permintaan barang baru yang perlu ditinjau dan disetujui gudang.')
            ->line('Kode Permintaan: '.$this->requestCode)
            ->action('Lihat Permintaan', url('/admin/inventory/requests/'.$this->requestId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_submitted',
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'company_id' => $this->companyId,
        ];
    }
}
