<?php

namespace Modules\SPK\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * In the technician's bell and on their phone: the reviewer sent the SPK back, with the reason they typed. That reason is
 * what the notification is for, so it travels with it, cut to what fits a lock screen.
 */
class WorkOrderRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const REASON_LENGTH = 200;

    public readonly string $reason;

    public function __construct(
        public readonly int $companyId,
        public readonly int $workOrderId,
        public readonly string $workOrderCode,
        string $reason,
    ) {
        $this->reason = Str::limit(trim($reason), self::REASON_LENGTH, '');
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
            'type' => 'spk_rejected',
            'work_order_id' => $this->workOrderId,
            'work_order_code' => $this->workOrderCode,
            'reason' => $this->reason,
            'company_id' => $this->companyId,
        ];
    }
}
