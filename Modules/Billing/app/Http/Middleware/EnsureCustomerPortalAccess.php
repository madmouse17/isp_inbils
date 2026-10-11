<?php

namespace Modules\Billing\Http\Middleware;

use App\Models\Core\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer portal is for customer logins only: the customer role and a Customer record linked to
 * the user in the effective company. Everything else is 403, never an empty page. The resolved
 * customer rides on the request so controllers cannot reach another one.
 */
class EnsureCustomerPortalAccess
{
    public const CUSTOMER_ATTRIBUTE = 'portal.customer';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->hasRole('customer'), 403);

        $customer = Customer::query()->where('user_id', $user->id)->first();

        abort_if($customer === null, 403, 'Akun ini belum terhubung dengan data pelanggan.');

        $request->attributes->set(self::CUSTOMER_ATTRIBUTE, $customer);

        return $next($request);
    }
}
