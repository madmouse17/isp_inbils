<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The end of every login: reached straight after the password, or after the second factor.
 */
trait OpensSession
{
    private function openSession(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);

        $request->session()->regenerate();
        // Every login starts in the default company, never in the one a previous login switched into.
        $request->session()->forget(CompanyService::SESSION_KEY);

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }
}
