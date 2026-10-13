<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ApiException;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivation revokes tokens at the model, so this only catches a writer that skipped the model (a bulk
 * update, a restore): the token that was just used is deleted and the call is a 401 the app answers by
 * signing out.
 */
final class EnsureApiUserIsActive
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            $this->tokens->revokeToken($user, $user->currentAccessToken(), 'account_inactive');

            throw new ApiException(401, 'account_inactive', 'This account is inactive.');
        }

        // The app acts in the home company only (no session to switch in): a suspended one is a refusal, not a sign-out.
        if (CompanyService::isSuspendedFor($user)) {
            throw new ApiException(403, 'company_inactive', 'The company of this account is suspended.');
        }

        return $next($request);
    }
}
