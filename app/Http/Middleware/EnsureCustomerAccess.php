<?php

namespace App\Http\Middleware;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer surfaces (the web portal and the customer app) are for customer logins only: the `portal.access` permission
 * in the effective company and a Customer record linked to the user there. Everything else is 403, never an empty page.
 * The resolved customer rides on the request so controllers cannot reach another one.
 */
class EnsureCustomerAccess
{
    public const CUSTOMER_ATTRIBUTE = 'portal.customer';

    /**
     * The Customer behind a customer login, or null for anyone else. The company is explicit, not the
     * authenticated one: the API checks it for a user who is not signed in yet (at login), so the permission
     * is read inside that company.
     */
    public static function customerFor(User $user): ?Customer
    {
        $companyId = CompanyService::idFor($user);

        if ($companyId === null) {
            return null;
        }

        return CompanyService::runAs($companyId, fn (): ?Customer => $user->can('portal.access')
            ? Customer::forCompany($companyId)->where('user_id', $user->id)->first()
            : null);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->can('portal.access'), 403);

        $customer = self::customerFor($user);

        abort_if($customer === null, 403, 'Akun ini belum terhubung dengan data pelanggan.');

        $request->attributes->set(self::CUSTOMER_ATTRIBUTE, $customer);

        return $next($request);
    }
}
