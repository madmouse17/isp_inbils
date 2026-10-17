<?php

namespace App\Http\Middleware\Platform;

use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAuditService;
use App\Support\PlatformSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every platform request after auth:platform (alias platform.account): the account must still be active and the
 * session must not have been idle for more than 30 minutes. A refused request ends the platform session for good
 * (guard logout, session invalidated, new CSRF token) and goes back to the platform login. An accepted request
 * refreshes the activity timestamp the next idle check is measured from.
 *
 * A missing timestamp counts as expired: only the platform login writes it, so a session without it did not come
 * through the login.
 */
class EnsurePlatformAccountIsActive
{
    public function __construct(private readonly PlatformAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $session = $request->session();
        $lastActivity = $session->get(PlatformSession::LAST_ACTIVITY);

        if (! $admin->is_active) {
            return $this->endSession($request, $admin, null);
        }

        if (! is_int($lastActivity) || now()->getTimestamp() - $lastActivity > PlatformSession::IDLE_SECONDS) {
            return $this->endSession($request, $admin, 'auth.idle_timeout');
        }

        $session->put(PlatformSession::LAST_ACTIVITY, now()->getTimestamp());

        return $next($request);
    }

    /** @param  string|null  $auditAction  the platform audit event of the end, when it is one worth keeping */
    private function endSession(Request $request, PlatformAdmin $admin, ?string $auditAction): Response
    {
        if ($auditAction !== null) {
            $this->audit->platform($auditAction, ['platform_admin_id' => $admin->id], $admin, $admin);
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login')->with('status', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
    }
}
