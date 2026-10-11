<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Services\Core\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The installs signed in to the user's account, across both apps, and the way to sign one out from another.
 */
class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = $request->user()->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->get();

        return DeviceResource::collection($tokens);
    }

    /** Only the user's own tokens are looked up: anyone else's id is a plain 404. */
    public function destroy(Request $request, int $id, ApiTokenService $tokens): Response
    {
        $user = $request->user();

        $tokens->revokeToken($user, $user->tokens()->findOrFail($id), 'device_revoked');

        return response()->noContent();
    }
}
