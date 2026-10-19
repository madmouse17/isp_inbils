<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\Core\DeviceToken;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Push\FcmSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        public readonly ?int $deviceTokenId = null,
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
        } catch (ApiException) {
            // Only the refusal itself ends the installs. Any other failure (a database or network outage) is retried and leaves them.
            DeviceToken::query()->where('user_id', $this->userId)->where('app', $this->app)->delete();

            return;
        }

        $tokens = DeviceToken::query()
            ->where('user_id', $this->userId)
            ->where('app', $this->app)
            ->reachable()
            ->when($this->deviceTokenId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $result = $sender->send($tokens, $this->message);

        Log::info('push.delivered', ['notification_id' => $this->message['data']['notification_id'], 'user_id' => $this->userId, 'app' => $this->app, ...$result]);

        if ($result['sent'] > 0) {
            // The platform-wide "last successful send" the integrations page shows: one key, no tenant data.
            Cache::forever('push:last_sent_at', now()->toIso8601String());
        }

        // Retrying after a partial delivery would send the same push twice to the installs it reached.
        if ($result['sent'] === 0 && $result['failed'] > 0) {
            throw new RuntimeException("FCM delivered nothing for notification {$this->message['data']['notification_id']}: {$result['failed']} failed.");
        }
    }

    /** Ids only: the exception message can name a token. The installs stay, the next push may still reach them. */
    public function failed(Throwable $e): void
    {
        Log::warning('push.send_failed', [
            'notification_id' => $this->message['data']['notification_id'],
            'user_id' => $this->userId,
            'app' => $this->app,
            'device_token_id' => $this->deviceTokenId,
        ]);
    }
}
