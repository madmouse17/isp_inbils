<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Not the global defer(): the Swoole extension in the Sail image defines a function of that name, and
// Laravel only defines its own global helper when the name is free.
use function Illuminate\Support\defer;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // The answer never depends on the address: an unknown one, a known one and one inside the broker's
        // wait time all get the same status. The link goes out after the response, so neither the body nor
        // the time it takes (mail transport included) says which one it was.
        defer(fn () => Password::sendResetLink($request->only('email')));

        return back()->with('status', __('If an account exists for that email address, we have emailed a password reset link.'));
    }
}
