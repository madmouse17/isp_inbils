<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Events\NullDispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogBatch;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ImpersonationService
{
    public const ADMIN_ID = 'impersonator_platform_admin_id';

    public const USER_ID = 'impersonation_user_id';

    public const COMPANY_ID = 'impersonation_company_id';

    public const ID = 'impersonation_id';

    public const REASON = 'impersonation_reason';

    public const STARTED_AT = 'impersonation_started_at';

    public const MODE = 'impersonation_mode';

    public const READ = 'read';

    public const WRITE = 'write';

    public const MAX_SECONDS = 1800;

    /**
     * A page of the tenant app, the only place the confirm page may send a support session back to: /admin/... or
     * /app-only, with a query. Segments are plain characters or escapes that cannot spell a dot, a slash or a
     * backslash, so no dot segment or second origin can be written; a fragment, a space, a control character and a
     * backslash are not in the alphabet at all.
     */
    private const TENANT_PAGE = '#\A/(?:admin|app-only)(?:/(?!\.{1,2}(?:[/?]|\z))(?:[A-Za-z0-9_.~-]|%(?!2[eEfF]|5[cC])[0-9A-Fa-f]{2})*)*(?:\?[A-Za-z0-9_.~!*()+,;=:@/?&$\[\]%-]*)?\z#';

    private const TENANT_PAGE_MAX_LENGTH = 2048;

    /** @var list<string> */
    private const KEYS = [
        self::ADMIN_ID,
        self::USER_ID,
        self::COMPANY_ID,
        self::ID,
        self::REASON,
        self::STARTED_AT,
        self::MODE,
    ];

    public function __construct(private readonly PlatformAuditService $audit) {}

    public function start(PlatformAdmin $admin, Company $company, User $user, string $reason, Session $session): ActiveImpersonation
    {
        if ($session->has(self::ID) || Auth::guard('web')->check()) {
            throw new ConflictHttpException('Akhiri sesi pengguna yang sedang aktif sebelum memulai akses dukungan.');
        }

        $companyIds = $this->companyIds($company);
        abort_unless($user->company_id !== null && in_array((int) $user->company_id, $companyIds, true), 404);

        $errors = [];
        if (! $company->is_active) {
            $errors[] = 'Perusahaan sedang ditangguhkan.';
        }
        if (! $user->is_active) {
            $errors[] = 'Pengguna tidak aktif.';
        }
        if (! CompanyService::canEnter($user)) {
            $errors[] = 'Pengguna tidak dapat masuk ke perusahaan aktif.';
        }

        $entryCompanyId = $this->entryCompanyId($user);
        if ($entryCompanyId === null || ! in_array($entryCompanyId, $companyIds, true)) {
            $errors[] = 'Pengguna tidak memiliki perusahaan aktif dalam tenant ini.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['impersonation' => $errors]);
        }

        $session->forget([
            CompanyService::SESSION_KEY,
            'auth.password_confirmed_at',
            'login.two_factor',
            'url.intended',
        ]);
        if ($entryCompanyId !== (int) $user->company_id) {
            $session->put(CompanyService::SESSION_KEY, $entryCompanyId);
        }

        $this->loginQuietly($user);

        $active = new ActiveImpersonation(
            id: (string) Str::uuid(),
            admin: $admin,
            user: $user,
            company: $company,
            reason: $reason,
            startedAt: now()->getTimestamp(),
            mode: self::READ,
        );

        $session->put([
            self::ADMIN_ID => (int) $admin->getKey(),
            self::USER_ID => (int) $user->getKey(),
            self::COMPANY_ID => (int) $company->getKey(),
            self::ID => $active->id,
            self::REASON => $active->reason,
            self::STARTED_AT => $active->startedAt,
            self::MODE => $active->mode,
        ]);
        CompanyService::forgetResolution();

        $this->record($active, 'impersonation.started', [
            'expires_at' => now()->addSeconds(self::MAX_SECONDS)->toIso8601String(),
        ]);

        return $active;
    }

    public function current(Session $session): ?ActiveImpersonation
    {
        if (! $session->has(self::ID)) {
            return null;
        }

        $adminId = $session->get(self::ADMIN_ID);
        $userId = $session->get(self::USER_ID);
        $companyId = $session->get(self::COMPANY_ID);
        $id = $session->get(self::ID);
        $reason = $session->get(self::REASON);
        $startedAt = $session->get(self::STARTED_AT);
        $mode = $session->get(self::MODE);

        if (! is_numeric($adminId) || ! is_numeric($userId) || ! is_numeric($companyId)
            || ! is_string($id) || ! Str::isUuid($id) || ! is_string($reason) || ! is_numeric($startedAt)
            || ! in_array($mode, [self::READ, self::WRITE], true)) {
            return null;
        }

        $admin = PlatformAdmin::query()->find((int) $adminId);
        $user = User::query()->find((int) $userId);
        $company = Company::query()->whereNull('parent_company_id')->find((int) $companyId);

        return $admin && $user && $company ? new ActiveImpersonation(
            $id,
            $admin,
            $user,
            $company,
            $reason,
            (int) $startedAt,
            $mode,
        ) : null;
    }

    public function changeMode(ActiveImpersonation $active, Session $session, string $mode): ActiveImpersonation
    {
        abort_unless(in_array($mode, [self::READ, self::WRITE], true), 422);

        if ($active->mode === $mode) {
            return $active;
        }

        $session->put(self::MODE, $mode);
        $changed = new ActiveImpersonation(
            $active->id,
            $active->admin,
            $active->user,
            $active->company,
            $active->reason,
            $active->startedAt,
            $mode,
        );
        $this->record($changed, 'impersonation.mode_changed', ['from' => $active->mode]);

        return $changed;
    }

    public function end(Session $session, string $reason): ?ActiveImpersonation
    {
        $active = $this->current($session);

        if ($active !== null) {
            $this->record($active, 'impersonation.ended', [
                'end_reason' => $reason,
                'duration_seconds' => max(0, now()->getTimestamp() - $active->startedAt),
            ]);
        }

        if ($session->has(self::ID)) {
            /** @var SessionGuard $guard */
            $guard = Auth::guard('web');
            $guard->logoutCurrentDevice();
        }

        $this->forget($session);
        if ($active !== null) {
            // The record the support session ran under is destroyed, not just left behind: a copied cookie opens nothing.
            $session->regenerate(destroy: true);
        }

        return $active;
    }

    public function forget(Session $session): void
    {
        $session->forget([
            ...self::KEYS,
            CompanyService::SESSION_KEY,
            'auth.password_confirmed_at',
            'login.two_factor',
            'url.intended',
        ]);
        CompanyService::forgetResolution();
    }

    /** $value when it names a page of the tenant app (see TENANT_PAGE), else null. */
    public static function tenantPage(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= self::TENANT_PAGE_MAX_LENGTH && preg_match(self::TENANT_PAGE, $value) === 1
            ? $value
            : null;
    }

    /** @return list<int> */
    public function companyIds(Company $company): array
    {
        return [
            (int) $company->getKey(),
            ...Company::query()->where('parent_company_id', $company->getKey())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    /** @param array<string, mixed> $extra */
    public function recordWrite(ActiveImpersonation $active, array $extra): void
    {
        $this->record($active, 'impersonation.write', $extra);
    }

    private function entryCompanyId(User $user): ?int
    {
        if ($user->company_id !== null && CompanyService::canActIn($user, (int) $user->company_id)) {
            return (int) $user->company_id;
        }

        return CompanyService::fallbackIdFor($user);
    }

    private function loginQuietly(User $user): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $events = $guard->getDispatcher();
        $guard->setDispatcher(new NullDispatcher($events));

        try {
            $guard->login($user, false);
        } finally {
            $guard->setDispatcher($events);
        }
    }

    /** @param array<string, mixed> $extra */
    private function record(ActiveImpersonation $active, string $action, array $extra = []): void
    {
        $batch = app(LogBatch::class);
        $previousUuid = $batch->uuid;
        $previousTransactions = $batch->transactions;
        $batch->setBatch($active->id);

        try {
            $base = [
                'impersonation_id' => $active->id,
                'platform_admin_id' => $active->admin->id,
                'user_id' => $active->user->id,
                'reason' => $active->reason,
                'mode' => $active->mode,
                ...$extra,
            ];
            $this->audit->platform($action, $base, $active->company, $active->admin);
            unset($base['impersonation_id'], $base['platform_admin_id']);
            $this->audit->support($action, [
                ...$base,
                'user_name' => $active->user->name,
            ], $active->user, $active->admin);
        } finally {
            $batch->uuid = $previousUuid;
            $batch->transactions = $previousTransactions;
        }
    }
}
