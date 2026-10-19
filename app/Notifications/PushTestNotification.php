<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * The test push of the apps' notification screen: a bell row like any other, whose push goes to the one install that
 * asked for it. It is not queued, so the bell row exists before the request answers; FCM goes through the queue.
 */
class PushTestNotification extends Notification
{
    public function __construct(
        public readonly int $deviceTokenId,
        public readonly string $companyName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'push_test', 'company_name' => $this->companyName];
    }
}
