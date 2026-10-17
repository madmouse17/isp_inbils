<?php

namespace App\Notifications\Channels;

use App\Http\Resources\NotificationResource;
use App\Jobs\SendPushNotification;
use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Push\FcmSender;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * The push twin of the bell: the notification's own toArray() runs through NotificationResource, so the title, the
 * message and the subject a phone shows are exactly the ones GET /notifications will list. A customer notification goes
 * to the customer app installs of the customer's login, a notification of a user to their technician app installs: a
 * customer install never receives a technician push.
 *
 * A notification opts in by adding FcmChannel::for($notifiable) to via(). That adds the channel only when push is on and
 * the recipient has an install a push can reach, so web-only users and installs without Firebase change nothing.
 * The channel itself only queues SendPushNotification, which sends after commit and retries.
 */
class FcmChannel
{
    /** @return list<class-string<self>> */
    public static function for(object $notifiable): array
    {
        $audience = self::audience($notifiable);

        if ($audience === null || ! FcmSender::isConfigured()) {
            return [];
        }

        $reachable = DeviceToken::query()->where('user_id', $audience['user_id'])->where('app', $audience['app'])->reachable()->exists();

        return $reachable ? [self::class] : [];
    }

    public function send(Customer|User $notifiable, Notification $notification): void
    {
        $audience = self::audience($notifiable);

        // A push never takes its notification down with it: the bell and the mail are already written.
        if ($audience !== null && method_exists($notification, 'toArray')) {
            rescue(fn () => Bus::dispatch(new SendPushNotification(
                $audience['user_id'],
                $audience['app'],
                $this->message($notifiable, $notification->id ?? (string) Str::uuid(), $notification->toArray($notifiable)),
            )));
        }
    }

    /** @return array{user_id: int, app: string}|null */
    private static function audience(object $notifiable): ?array
    {
        return match (true) {
            $notifiable instanceof Customer => $notifiable->user_id === null ? null : ['user_id' => (int) $notifiable->user_id, 'app' => ApiTokenService::CUSTOMER],
            $notifiable instanceof User => ['user_id' => (int) $notifiable->getKey(), 'app' => ApiTokenService::TECHNICIAN],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data  what the notification stores in the bell
     * @return array{title: string, body: string, data: array<string, string>}
     */
    private function message(Customer|User $notifiable, string $id, array $data): array
    {
        $row = new DatabaseNotification(['id' => $id, 'notifiable_type' => $notifiable->getMorphClass(), 'data' => $data]);
        // An api/* request leaves out the web-only detail_url, which needs a signed-in user a queue worker does not have.
        $bell = (new NotificationResource($row))->resolve(Request::create('/api/v1/notifications'));

        return [
            'title' => $bell['title'],
            'body' => $bell['message'],
            'data' => [
                'type' => $bell['type'],
                'subject_type' => $bell['subject']['type'] ?? '',
                'subject_id' => (string) ($bell['subject']['id'] ?? ''),
                'notification_id' => $id,
                'title' => $bell['title'],
                'body' => $bell['message'],
            ],
        ];
    }
}
