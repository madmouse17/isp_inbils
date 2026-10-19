<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Requests\Api\V1\PushTestRequest;
use App\Models\Core\DeviceToken;
use App\Notifications\PushTestNotification;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyService;
use App\Services\Push\FcmSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PushTestController extends Controller
{
    /** How long the app waits for the push before it tells the user nothing arrived. */
    private const WAIT_SECONDS = 30;

    /**
     * Send a test push to this install
     *
     * Writes a `push_test` notification in the bell and pushes it to the install that holds `token`, never to the user's
     * other phones. 202: the push is queued, the app waits `wait_seconds` for it. 409 `push_token_not_registered`: the
     * server does not know this token for this sign-in (register it with `POST me/device-token` and retry), 503
     * `push_unavailable`: push is off on the server. At most 3 a minute per user.
     *
     * @response 202 array{data: array{notification_id: string, device: array{id: string, name: string|null}, wait_seconds: int}}
     */
    public function __invoke(PushTestRequest $request): JsonResponse
    {
        if (! FcmSender::isConfigured()) {
            throw new ApiException(503, 'push_unavailable', 'Notifikasi push belum aktif di server.');
        }

        $user = $request->user();

        $device = DeviceToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', DeviceToken::hash($request->validated('token')))
            ->where('personal_access_token_id', $user->currentAccessToken()->getKey())
            ->reachable()
            ->first();

        // The audience rule of FcmChannel: the customer app reads the customer's bell, the technician app the user's.
        $notifiable = match ($device?->app) {
            ApiTokenService::CUSTOMER => EnsureCustomerAccess::customerFor($user),
            ApiTokenService::TECHNICIAN => $user,
            default => null,
        };

        if ($device === null || $notifiable === null) {
            throw new ApiException(409, 'push_token_not_registered', 'Perangkat ini belum terdaftar untuk notifikasi. Daftarkan ulang lalu coba lagi.');
        }

        $notification = new PushTestNotification($device->id, CompanyService::current()->name);
        $notification->id = (string) Str::uuid();
        $notifiable->notify($notification);

        return response()->json(['data' => [
            'notification_id' => $notification->id,
            'device' => ['id' => $device->public_id, 'name' => $device->device_name],
            'wait_seconds' => self::WAIT_SECONDS,
        ]], 202);
    }
}
