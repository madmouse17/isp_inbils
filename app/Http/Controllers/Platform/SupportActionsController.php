<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\SupportToolsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupportActionsController extends Controller
{
    public function resetTwoFactor(Request $request, Company $company, User $user, SupportToolsService $support): RedirectResponse
    {
        $this->ensureReach($company, $user, $support);
        $support->resetTwoFactor($this->admin($request), $user);

        return $this->shown($company, 'Autentikasi dua langkah pengguna direset.');
    }

    public function resendInvitation(Request $request, Company $company, User $user, SupportToolsService $support): RedirectResponse
    {
        $this->ensureReach($company, $user, $support);
        $support->resendInvitation($this->admin($request), $user);

        return $this->shown($company, 'Undangan admin dikirim ulang.');
    }

    public function unlock(Request $request, Company $company, User $user, SupportToolsService $support): RedirectResponse
    {
        $this->ensureReach($company, $user, $support);
        $support->unlockLogin($this->admin($request), $user);

        return $this->shown($company, 'Kunci autentikasi dua langkah pengguna dibuka.');
    }

    private function ensureReach(Company $company, User $user, SupportToolsService $support): void
    {
        abort_unless($support->isHomedIn($company, $user), 404);
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }

    private function shown(Company $company, string $message): RedirectResponse
    {
        return redirect()->route('platform.companies.show', $company)->with('success', $message);
    }
}
