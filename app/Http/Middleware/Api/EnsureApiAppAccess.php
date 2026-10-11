<?php

namespace App\Http\Middleware\Api;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Services\Core\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app rules (ApiTokenService::ensureMayUse) on every request, not only at login: a permission taken away
 * or a profile deactivated stops the phone at once. For a customer token the resolved Customer rides on the
 * request, exactly as the web portal's middleware does, so endpoints never look it up themselves.
 */
final class EnsureApiAppAccess
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $customer = $this->tokens->ensureMayUse($user, $this->tokens->appOf($user));

        if ($customer !== null) {
            $request->attributes->set(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE, $customer);
        }

        return $next($request);
    }
}
