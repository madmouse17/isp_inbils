<?php

namespace Modules\SPK\Notifications;

use App\Notifications\Channels\FcmChannel;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

/**
 * In the customer app: the technician has left for the visit. In-app only; a WhatsApp template would need Meta's
 * approval. It carries the technician's first name and the SPK id the app opens, never the technician's phone.
 *
 * One "Berangkat" is one notification: the id is made of the SPK and the moment the technician left, so the same trip can
 * never be stored twice (the primary key of `notifications` refuses it, and the sender looks first). A later trip, after a
 * rework or a new technician, is another moment and so another notification.
 */
class TechnicianEnRouteNotification extends Notification
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $workOrderId,
        public readonly string $technicianFirstName,
        CarbonInterface $leftAt,
    ) {
        $this->id = self::idFor($workOrderId, $leftAt);
    }

    /** The id of the notification of this SPK's trip that began at $leftAt: the same for the same second, always a UUID. */
    public static function idFor(int $workOrderId, CarbonInterface $leftAt): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "inbils:technician-en-route:{$workOrderId}:{$leftAt->getTimestamp()}")->toString();
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
            'type' => 'technician_en_route',
            'work_order_id' => $this->workOrderId,
            'company_id' => $this->companyId,
            'technician_first_name' => $this->technicianFirstName,
        ];
    }
}
