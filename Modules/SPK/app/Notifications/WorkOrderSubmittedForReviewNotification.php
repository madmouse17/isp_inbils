<?php

namespace Modules\SPK\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\SPK\Models\WorkOrder;

/**
 * In the bell of everyone who may approve (and on the phone of a technician-app install): a technician submitted an SPK and it
 * waits for a decision. The code, the technician's name and the ids only; the page it opens is the SPK itself.
 */
class WorkOrderSubmittedForReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $workOrderId,
        public readonly string $workOrderCode,
        public readonly ?string $technicianName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'spk_submitted',
            'work_order_id' => WorkOrder::withoutCompany()->whereKey($this->workOrderId)->value('public_id'),
            'work_order_code' => $this->workOrderCode,
            'technician_name' => $this->technicianName,
            'company_id' => $this->companyId,
        ];
    }
}
