<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Resources\Api\V1\MeResource;
use App\Services\Core\ApiTokenService;
use App\Services\Core\UserProfileService;

class MeProfileController extends Controller
{
    public function __invoke(UpdateMeRequest $request, ApiTokenService $tokens): MeResource
    {
        $user = $request->user();
        $app = $tokens->appOf($user);

        UserProfileService::update($user, $app, $request->validated());

        return new MeResource($user, $app);
    }
}
