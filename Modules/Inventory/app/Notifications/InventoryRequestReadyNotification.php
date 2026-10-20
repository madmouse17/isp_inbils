<?php

namespace Modules\Inventory\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToInventoryRequest;

/** In the bell, and on the phone of a technician-app install, of the person who asked: the warehouse prepared the goods for pickup. */
class InventoryRequestReadyNotification extends Notification implements ShouldQueue
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
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_ready',
            'request_id' => $this->requestPublicId(),
            'request_code' => $this->requestCode,
            'company_id' => $this->companyId,
        ];
    }
}
