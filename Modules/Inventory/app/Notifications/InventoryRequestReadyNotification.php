<?php

namespace Modules\Inventory\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryRequestReadyNotification extends Notification implements ShouldQueue
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
        return ['mail', 'database', ...FcmChannel::for($notifiable)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Permintaan Barang Siap Diambil: '.$this->requestCode)
            ->line('Barang untuk permintaan Anda sudah disiapkan gudang dan siap diambil.')
            ->line('Kode Permintaan: '.$this->requestCode)
            ->action('Lihat Detail', url('/admin/inventory/requests/'.$this->requestId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_ready',
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'company_id' => $this->companyId,
        ];
    }
}
