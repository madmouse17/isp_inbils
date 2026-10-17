<?php

namespace Modules\SPK\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\SPK\Models\WorkOrder;

/**
 * In the technician's bell and on their phone: the reviewer approved the SPK they submitted. The code and the ids only.
 */
class WorkOrderApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $workOrderId,
        public readonly string $workOrderCode,
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
            'type' => 'spk_approved',
            'work_order_id' => WorkOrder::withoutCompany()->whereKey($this->workOrderId)->value('public_id'),
            'work_order_code' => $this->workOrderCode,
            'company_id' => $this->companyId,
        ];
    }
}
