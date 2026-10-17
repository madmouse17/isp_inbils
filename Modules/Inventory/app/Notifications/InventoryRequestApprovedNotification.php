<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToInventoryRequest;

class InventoryRequestApprovedNotification extends Notification implements ShouldQueue
{
    use LinksToInventoryRequest;
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
            ->subject('Permintaan Barang Disetujui: '.$this->requestCode)
            ->line('Permintaan barang Anda telah disetujui dan stok telah direservasi.')
            ->line('Kode Permintaan: '.$this->requestCode)
            ->action('Lihat Detail', $this->requestUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_approved',
            'request_id' => $this->requestPublicId(),
            'request_code' => $this->requestCode,
            'company_id' => $this->companyId,
        ];
    }
}
