<?php

namespace Modules\Billing\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Core\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The portal page for a flagged (legacy) customer login. It cannot change a password: the flag clears only
 * when the customer completes a password reset from the emailed link (see ClearLegacyPasswordGate), which
 * proves they control the mailbox. Accepting the old password as proof would accept the phone number.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): InertiaResponse|RedirectResponse
    {
        if (! $request->user()->getAttribute('must_change_password')) {
            return redirect()->route('portal.invoices.index');
        }

        return Inertia::render('Portal/Password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->getAttribute('must_change_password') && $user->is_active, 403);

        CustomerService::sendAccessLink($user);

        return back()->with('success', 'Link atur kata sandi dikirim ke '.$user->email.'.');
    }
}
