<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToInventoryRequest;

/** In the bell of everyone who may approve: a request was submitted and waits for a decision. Mail is only the daily digest. */
class InventoryRequestSubmittedNotification extends Notification implements ShouldQueue
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
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_request_submitted',
            'request_id' => $this->requestPublicId(),
            'request_code' => $this->requestCode,
            'company_id' => $this->companyId,
        ];
    }
}
