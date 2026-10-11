<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Services\Core\ApiTokenService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, ApiTokenService $tokens): MeResource
    {
        $user = $request->user();

        return new MeResource($user, $tokens->appOf($user));
    }
}
