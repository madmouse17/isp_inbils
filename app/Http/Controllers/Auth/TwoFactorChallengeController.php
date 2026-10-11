<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\OpensSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    use OpensSession;

    /**
     * Display the second-factor step; without a password-checked login waiting, start over.
     */
    public function create(Request $request, TwoFactorService $twoFactor): Response|RedirectResponse
    {
        if ($twoFactor->pendingChallenge($request->session()) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Finish the login that POST /login parked: the session is only opened here, once the code passes.
     */
    public function store(TwoFactorChallengeRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $twoFactor->pendingChallenge($request->session());

        if ($pending === null) {
            return redirect()->route('login');
        }

        $request->verify($pending['user'], $twoFactor);

        $twoFactor->abandonChallenge($request->session());

        return $this->openSession($request, $pending['user'], $pending['remember']);
    }
}
