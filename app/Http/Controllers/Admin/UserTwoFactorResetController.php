<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\TwoFactorService;
use App\Support\RecordNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Restores access for a colleague who lost both their authenticator and their recovery codes.
 */
class UserTwoFactorResetController extends Controller
{
    public function __invoke(Request $request, User $user, TwoFactorService $twoFactor): RedirectResponse
    {
        // The same reach as the other user pages: users homed in the company the actor works in.
        RecordNotFound::abortUnless($user->company_id === CompanyService::currentId());
        Gate::authorize('resetTwoFactor', $user);

        $twoFactor->adminReset($request->user(), $user);

        return back()->with('success', 'Autentikasi dua langkah direset. '.$user->name.' bisa masuk hanya dengan kata sandi, lalu mengaturnya lagi.');
    }
}
