<?php

namespace App\Services\Push;

use App\Models\Core\DeviceToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\SendReport;

/**
 * The one place a push leaves the server: one message to every install of a user, through FCM HTTP v1. Tokens that
 * FCM reports as unregistered or invalid are deleted on the spot. Everything it logs is the notification id, the last
 * characters of a token and an error class: never a message, never a whole token (FCM's own exception messages
 * quote the token, so they are not logged either).
 */
final class FcmSender
{
    /** The Android notification channel the apps create at start (importance high), so a background push heads-up. */
    public const CHANNEL_ID = 'inbils';

    /** A push that waited a day for an offline phone says nothing the bell does not say better. */
    private const TTL = '86400s';

    public function __construct(private readonly Messaging $messaging) {}

    /** Push is off until the service-account path is set; nothing here may touch the Firebase client before that. */
    public static function isConfigured(): bool
    {
        return filled(config('firebase.projects.'.config('firebase.default').'.credentials'));
    }

    /**
     * @param  Collection<int, DeviceToken>  $tokens
     * @param  array{title: string, body: string, data: array<string, string>}  $message  data values are strings, as FCM requires
     * @param  bool  $validateOnly  ask FCM to check the request and the credentials without delivering anything
     * @return array{sent: int, stale: int, failed: int} stale tokens FCM no longer accepts (deleted unless $validateOnly); failed
     *                                                   tokens a retry could still reach
     */
    public function send(Collection $tokens, array $message, bool $validateOnly = false): array
    {
        $report = $this->messaging->sendMulticast($this->cloudMessage($message), $tokens->pluck('token')->all(), $validateOnly);

        // SenderIdMismatch is also an "unknown token" to the SDK, but means the server key points at the wrong
        // Firebase project. Deleting every install then would turn one deploy mistake into data loss. Only an
        // unregistered token (NotFound) or a syntactically invalid token (InvalidMessage naming the token) is stale.
        $stale = $report->failures()
            ->filter(static fn (SendReport $item): bool => $item->error() instanceof NotFound
                || ($item->error() instanceof InvalidMessage && $item->messageTargetWasInvalid()))
            ->map(static fn (SendReport $item): string => $item->target()->value());

        $stale = array_values(array_unique($stale));

        if ($stale !== [] && ! $validateOnly) {
            DeviceToken::query()->whereIn('token_hash', array_map(DeviceToken::hash(...), $stale))->delete();
        }

        $failures = array_filter(
            $report->failures()->getItems(),
            fn (SendReport $item): bool => ! in_array($item->target()->value(), $stale, true),
        );

        foreach ($failures as $failure) {
            Log::warning('push.send_failed', [
                'notification_id' => $message['data']['notification_id'],
                'token' => substr($failure->target()->value(), -6),
                'error' => $failure->error() === null ? null : class_basename($failure->error()),
            ]);
        }

        return ['sent' => count($report->successes()), 'stale' => count($stale), 'failed' => count($failures)];
    }

    /** @param array{title: string, body: string, data: array<string, string>} $message */
    private function cloudMessage(array $message): CloudMessage
    {
        return CloudMessage::fromArray([
            'notification' => ['title' => $message['title'], 'body' => $message['body']],
            'data' => $message['data'],
            'android' => [
                'priority' => 'high',
                'ttl' => self::TTL,
                // The same id replaces a notification instead of stacking a copy: a retried job cannot show twice.
                'notification' => ['channel_id' => self::CHANNEL_ID, 'tag' => $message['data']['notification_id']],
            ],
        ]);
    }
}
