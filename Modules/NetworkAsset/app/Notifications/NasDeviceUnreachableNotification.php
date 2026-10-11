<?php

namespace Modules\NetworkAsset\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One per reachable → unreachable transition of a NAS router. Never carries credentials or router output. */
class NasDeviceUnreachableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $nasDeviceId,
        public readonly string $assetCode,
        public readonly string $errorKind,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('NAS router unreachable: '.$this->assetCode)
            ->line('Inbils cannot reach the NAS router '.$this->assetCode.'.')
            ->line('Error kind: '.$this->errorKind)
            ->line('Network changes for its subscribers wait in the queue and are retried by the 15-minute sync once it answers again.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'nas_device_unreachable',
            'nas_device_id' => $this->nasDeviceId,
            'asset_code' => $this->assetCode,
            'error_kind' => $this->errorKind,
        ];
    }
}
