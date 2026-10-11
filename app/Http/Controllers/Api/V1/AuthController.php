<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\TwoFactorRequest;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

// Not the global defer(): the Swoole extension in the Sail image defines a function of that name, and
// Laravel only defines its own global helper when the name is free.
use function Illuminate\Support\defer;

/**
 * Sign in, second factor, password reset link, token rotation and sign out for the two mobile apps.
 * The rules live in ApiTokenService; this only moves JSON in and out.
 */
class AuthController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * A user with a confirmed second factor gets a challenge and nothing else: what the account may do in the
     * app is only checked once the code passed, so a password alone reveals nothing about it.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->validateCredentials();
        $app = $request->validated('app');
        $device = $request->validated('device_name');

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['data' => $this->tokens->startChallenge($user, $app, $device)], 202);
        }

        return $this->signedIn($this->tokens->issue($user, $app, $device), $user, $app);
    }

    public function twoFactor(TwoFactorRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $id = $request->validated('challenge_id');
        $pending = $this->tokens->pendingChallenge($id);

        $request->claimAttempt($pending['user']);
        $this->tokens->ensureCodeAttemptsLeft($id);

        try {
            $request->verifyCode($pending['user'], $twoFactor);
        } catch (ValidationException $e) {
            $this->tokens->recordWrongCode($id);

            throw $e;
        }

        $this->tokens->closeChallenge($id);

        return $this->signedIn(
            $this->tokens->issue($pending['user'], $pending['app'], $pending['device_name']),
            $pending['user'],
            $pending['app'],
        );
    }

    /**
     * The answer never depends on the address, and the link goes out after the response, so neither the body
     * nor the time it takes says whether an account exists (as on the web).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        defer(fn () => Password::sendResetLink($request->only('email')));

        return response()->json(['message' => __('If an account exists for that email address, we have emailed a password reset link.')], 202);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $this->tokens->rotate($user, $user->currentAccessToken());

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
        ]]);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        $this->tokens->revokeToken($user, $user->currentAccessToken(), 'logout');

        return response()->noContent();
    }

    private function signedIn(NewAccessToken $token, User $user, string $app): JsonResponse
    {
        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user' => (new MeResource($user, $app))->resolve(),
        ]]);
    }
}
