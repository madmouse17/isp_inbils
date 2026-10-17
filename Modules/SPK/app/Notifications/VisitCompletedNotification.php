<?php

namespace Modules\SPK\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In the customer app and on the customer's phone: the visit to one of their subscriptions is done (the reviewer approved
 * the SPK). It carries the SPK id the app opens, never the technician's name or phone.
 */
class VisitCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $workOrderId,
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
            'type' => 'visit_completed',
            'work_order_id' => $this->workOrderId,
            'company_id' => $this->companyId,
        ];
    }
}
