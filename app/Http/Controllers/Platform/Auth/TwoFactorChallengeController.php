<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\OpensPlatformSession;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\PlatformAdmin;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    use OpensPlatformSession;

    /**
     * Display the second-factor step; without a password-checked login waiting, start over.
     */
    public function create(Request $request, TwoFactorService $twoFactor): Response|RedirectResponse
    {
        if ($twoFactor->pendingChallenge($request->session(), PlatformAdmin::class) === null) {
            return redirect()->route('platform.login');
        }

        return Inertia::render('Platform/Auth/TwoFactorChallenge');
    }

    /**
     * Finish the login that POST /platform/login parked: the session is only opened here, once the code passes.
     */
    public function store(TwoFactorChallengeRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $twoFactor->pendingChallenge($request->session(), PlatformAdmin::class);

        if ($pending === null) {
            return redirect()->route('platform.login');
        }

        /** @var PlatformAdmin $admin */
        $admin = $pending['user'];

        $request->verify($admin, $twoFactor);

        $twoFactor->abandonChallenge($request->session(), PlatformAdmin::class);

        return $this->openPlatformSession($request, $admin, twoFactorPassed: true);
    }
}
