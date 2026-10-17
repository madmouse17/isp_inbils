<?php

namespace Modules\Service\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;
use Modules\Service\Models\Announcement;

class AnnouncementPublishedNotification extends Notification
{
    public function __construct(private readonly Announcement $announcement) {}

    /** Kinds that also reach the phone: an outage or a maintenance window is news a customer wants at once; `info` stays in the bell. */
    private const PUSH_KINDS = ['outage', 'maintenance'];

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...(in_array($this->announcement->kind, self::PUSH_KINDS, true) ? FcmChannel::for($notifiable) : [])];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement_published',
            'announcement_id' => $this->announcement->public_id,
            'company_id' => $this->announcement->company_id,
            'kind' => $this->announcement->kind,
            'title' => $this->announcement->title,
            'subject' => ['type' => 'announcement', 'id' => $this->announcement->public_id],
        ];
    }
}
