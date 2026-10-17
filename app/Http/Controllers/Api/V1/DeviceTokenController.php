<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DestroyDeviceTokenRequest;
use App\Http\Requests\Api\V1\StoreDeviceTokenRequest;
use App\Http\Resources\Api\V1\DeviceTokenResource;
use App\Services\Core\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The FCM token of the signed-in install, which is where the server sends this user's pushes.
 */
class DeviceTokenController extends Controller
{
    /**
     * Register this install's push token
     *
     * Send it after sign-in and whenever Firebase hands the app a new one. A token the server already knows is moved to
     * this user and this sign-in (200), a new one is stored (201). Logging out, a revoked sign-in or an expired one
     * ends pushes to the install, so register again after the next login.
     *
     * @response array{data: array{id: int}}
     */
    public function store(StoreDeviceTokenRequest $request, DeviceTokenService $tokens): JsonResponse
    {
        $access = $request->user()->currentAccessToken();
        abort_unless($access instanceof PersonalAccessToken, 401);

        [$row, $created] = $tokens->register($request->user(), $access, $request->validated('app'), $request->validated('token'), $request->validated('device_name'));

        return (new DeviceTokenResource($row))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Forget this install's push token (sign-out)
     *
     * Always 204: a token that is unknown, or that belongs to another user, is left alone and not revealed.
     *
     * @bodyParam token string required The FCM registration token of this install. Example: dQw4w9WgXcQ:APA91bFCM
     */
    public function destroy(DestroyDeviceTokenRequest $request, DeviceTokenService $tokens): Response
    {
        $tokens->forget($request->user(), $request->validated('token'));

        return response()->noContent();
    }
}
