<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use Illuminate\Http\Response;

class MePasswordController extends Controller
{
    /** Saving the new password deletes every token of the user (User::booted), this one included: the app signs in again. */
    public function __invoke(ChangePasswordRequest $request): Response
    {
        $user = $request->user();

        $request->verifyCurrentPassword($user);
        $user->update(['password' => $request->validated('password')]);

        return response()->noContent();
    }
}
