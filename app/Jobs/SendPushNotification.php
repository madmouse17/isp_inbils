<?php

namespace App\Jobs;

use App\Models\Core\DeviceToken;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Push\FcmSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One push for one notification and one user: the message goes to every install that user has of the app, found when the
 * job runs, so a token registered meanwhile is included and one that was signed out is not. Dispatched after commit, so
 * a rolled-back change pushes nothing. The message was built from the same data as the bell row and holds no token.
 */
final class SendPushNotification implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @param array{title: string, body: string, data: array<string, string>} $message */
    public function __construct(
        public readonly int $userId,
        public readonly string $app,
        public readonly array $message,
    ) {}

    /** @return list<int> seconds before attempts 2 and 3 */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(FcmSender $sender, ApiTokenService $access): void
    {
        if (! FcmSender::isConfigured()) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            DeviceToken::query()->where('user_id', $this->userId)->delete();

            return;
        }

        try {
            $access->ensureMayUse($user, $this->app);
        } catch (\Throwable) {
            DeviceToken::query()->where('user_id', $this->userId)->where('app', $this->app)->delete();

            return;
        }

        $tokens = DeviceToken::query()->where('user_id', $this->userId)->where('app', $this->app)->reachable()->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $result = $sender->send($tokens, $this->message);

        Log::info('push.delivered', ['notification_id' => $this->message['data']['notification_id'], 'user_id' => $this->userId, 'app' => $this->app, ...$result]);

        // Retrying after a partial delivery would send the same push twice to the installs it reached.
        if ($result['sent'] === 0 && $result['failed'] > 0) {
            throw new RuntimeException("FCM delivered nothing for notification {$this->message['data']['notification_id']}: {$result['failed']} failed.");
        }
    }
}
