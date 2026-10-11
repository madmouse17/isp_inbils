<?php

namespace Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money screens need a credential only the customer knows. Legacy accounts (the phone number was the password)
 * are flagged by migration and stay locked out of the invoice pages until the customer completes a password
 * reset from an emailed link.
 */
class EnsurePortalPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->getAttribute('must_change_password')) {
            return redirect()->route('portal.password.edit')
                ->with('warning', 'Atur kata sandi baru lewat link di email Anda sebelum membuka tagihan.');
        }

        return $next($request);
    }
}
