<?php

namespace Tests\Feature\Push\Fixtures;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/** The smallest notification that opts in to push: a bell row with the given data, and the push twin of it. */
class ProbePushNotification extends Notification
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
