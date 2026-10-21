<?php

namespace App\Http\Middleware;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Platform\ActiveImpersonation;
use App\Services\Platform\ImpersonationService;
use App\Support\PlatformSession;
use App\Support\PublicId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Spatie\Activitylog\CauserResolver;
use Spatie\Activitylog\LogBatch;
use Symfony\Component\HttpFoundation\Response;

class EnforceImpersonation
{
    public const READ_ONLY_MESSAGE = 'Mode baca saja: perubahan tidak diizinkan selama akses dukungan. Aktifkan mode ubah dari banner untuk melanjutkan.';

    public const SECURITY_MESSAGE = 'Pengaturan akun pribadi tidak tersedia selama akses dukungan.';

    public const COMPANY_SWITCH_MESSAGE = 'Perpindahan ke perusahaan di luar tenant tidak diizinkan selama akses dukungan.';

    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(ImpersonationService::ID)) {
            return $next($request);
        }

        $active = $this->impersonation->current($request->session());
        if ($active === null) {
            $this->impersonation->end($request->session(), 'platform_session_ended');
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Inertia::location(route('platform.login'));
        }

        if ($active->expired()) {
            return $this->leave($request, $active, 'expired');
        }

        $admin = Auth::guard('platform')->user();
        $lastActivity = $request->session()->get(PlatformSession::LAST_ACTIVITY);
        $twoFactorVerified = $request->session()->get(PlatformSession::TWO_FACTOR_VERIFIED);
        if (! $admin instanceof PlatformAdmin
            || ! $admin->is($active->admin)
            || ! $active->admin->is_active
            || ! $active->admin->hasTwoFactorEnabled()
            || ! is_int($twoFactorVerified)
            || ! is_int($lastActivity)
            || now()->getTimestamp() - $lastActivity > PlatformSession::IDLE_SECONDS) {
            return $this->leave($request, $active, 'platform_session_ended');
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! $user->is($active->user) || ! $active->user->is_active) {
            return $this->leave($request, $active, 'target_inactive');
        }

        $active->company->refresh();
        $companyIds = $this->impersonation->companyIds($active->company);
        $entryCompanyId = CompanyService::idFor($active->user) ?? CompanyService::fallbackIdFor($active->user);
        if (! $active->company->is_active
            || ! CompanyService::canEnter($active->user)
            || $entryCompanyId === null
            || ! in_array($entryCompanyId, $companyIds, true)) {
            return $this->leave($request, $active, 'tenant_suspended');
        }

        $request->session()->put(PlatformSession::LAST_ACTIVITY, now()->getTimestamp());
        $request->attributes->set('impersonation', $active);

        $resolver = app(CauserResolver::class);
        $batch = app(LogBatch::class);
        $previousUuid = $batch->uuid;
        $previousTransactions = $batch->transactions;
        $resolver->setCauser($active->admin);
        $batch->setBatch($active->id);

        try {
            $response = $this->response($request, $next, $active, $companyIds);

            if ($active->writable() && ! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! $request->routeIs('logout')) {
                $this->impersonation->recordWrite($active, [
                    'route' => $request->route()?->getName(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                ]);
            }

            return $response;
        } finally {
            $batch->uuid = $previousUuid;
            $batch->transactions = $previousTransactions;
            $resolver->setCauser(null);
        }
    }

    /** @param list<int> $companyIds */
    private function response(Request $request, Closure $next, ActiveImpersonation $active, array $companyIds): Response
    {
        if ($request->routeIs('logout')) {
            return $this->leave($request, $active, 'logout');
        }

        // Own-account settings and the reveal of a customer's network secret stay closed in both modes: the secret is
        // read with a POST, which write mode would otherwise let through.
        if ($request->routeIs('profile.*', 'password.*', 'two-factor.*', 'verification.*', 'admin.subscriptions.network-account.reveal')
            || $this->targetsOwnUser($request, $active)
            || $this->setsLoginPassword($request)) {
            return $this->deny($request, self::SECURITY_MESSAGE);
        }

        if ($request->routeIs('admin.company.switch')) {
            $companyId = PublicId::resolveOne(Company::class, $request->input('company_id'));
            if ($companyId === null || ! in_array($companyId, $companyIds, true)) {
                return $this->deny($request, self::COMPANY_SWITCH_MESSAGE);
            }
        }

        if (! $active->writable()
            && ! $request->isMethod('GET')
            && ! $request->isMethod('HEAD')
            && ! $request->routeIs('admin.company.switch')) {
            return $this->deny($request, self::READ_ONLY_MESSAGE);
        }

        return $next($request);
    }

    private function targetsOwnUser(Request $request, ActiveImpersonation $active): bool
    {
        if (! $request->routeIs('admin.users.update', 'admin.users.destroy', 'admin.users.two-factor.reset', 'admin.users.roles.update')) {
            return false;
        }

        $target = $request->route('user');

        return $target instanceof User
            ? $target->is($active->user)
            : is_numeric($target) && (int) $target === (int) $active->user->getKey();
    }

    private function setsLoginPassword(Request $request): bool
    {
        return $request->routeIs('admin.users.store', 'admin.users.update')
            && filled($request->input('password'));
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->header('X-Inertia') && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return back(303)->with('error', $message);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        // A page visit gets the standalone error page with the reason, as every other refusal does: bare text left a white
        // page for an address typed in the browser and a raw text in the iframe the Inertia client shows for a non-page.
        return Inertia::render('Error', ['status' => 403, 'message' => $message, 'requestId' => $request->attributes->get('request_id')])
            ->toResponse($request)
            ->setStatusCode(403);
    }

    private function leave(Request $request, ActiveImpersonation $active, string $reason): Response
    {
        $this->impersonation->end($request->session(), $reason);

        if ($reason === 'platform_session_ended') {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Inertia::location(route('platform.login'));
        }

        return Inertia::location(route('platform.companies.show', $active->company));
    }
}
