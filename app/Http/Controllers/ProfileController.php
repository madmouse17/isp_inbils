<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\User;
use App\Services\Core\TwoFactorService;
use App\Support\UserDeletionGuard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form. The two-factor status carries counts and flags only; the
     * setup key and recovery codes reach the page once, as Inertia flash data.
     */
    public function edit(Request $request, TwoFactorService $twoFactor): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'twoFactor' => $twoFactor->status($user),
            // The switch is offered to whoever has warehouse work the digest can list.
            'notificationPreferences' => [
                'inventory_digest' => (bool) $user->inventory_digest,
                'eligible' => collect(User::INVENTORY_DIGEST_PERMISSIONS)->contains(fn (string $permission): bool => $user->can($permission)),
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Save the person's own mail preferences.
     */
    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $request->user()->forceFill(['inventory_digest' => $request->boolean('inventory_digest')])->save();

        return back(303)->with('success', 'Preferensi notifikasi disimpan.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        UserDeletionGuard::ensureDeletable($user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
