<?php

namespace App\Services\Platform;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Queries\Platform\PlatformTenantListQuery;
use App\Services\Core\CompanyService;
use App\Services\Core\TwoFactorService;
use App\Support\PermissionCatalogue;
use App\Support\SecurityTrail;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportToolsService
{
    public const WINDOW_DAYS = 30;

    private const INVITATION_ATTEMPTS = 6;

    private const INVITATION_DECAY_SECONDS = 60;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly PlatformAuditService $audit,
    ) {}

    public function isHomedIn(Company $root, User $user): bool
    {
        return Company::query()
            ->whereKey($user->company_id)
            ->where(fn ($companies) => $companies->whereKey($root->id)->orWhere('parent_company_id', $root->id))
            ->exists();
    }

    public function resetTwoFactor(PlatformAdmin $actor, User $user): void
    {
        $this->twoFactor->adminReset($actor, $user);
        $this->record($actor, $user, 'user.two_factor_reset');
    }

    public function resendInvitation(PlatformAdmin $actor, User $user): void
    {
        if (! $this->canResendInvitation($user)) {
            throw ValidationException::withMessages([
                'invitation' => 'Undangan hanya dapat dikirim kepada admin aktif yang belum pernah masuk.',
            ]);
        }

        $key = 'platform-invitation|'.$user->id;
        $attempts = RateLimiter::hit($key, self::INVITATION_DECAY_SECONDS);

        if ($attempts > self::INVITATION_ATTEMPTS) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                'Terlalu banyak pengiriman undangan. Coba lagi nanti.',
                headers: ['Retry-After' => $seconds],
            );
        }

        $user->notify(new TenantAdminInvitation((int) $user->company_id, $user->id));
        $this->record($actor, $user, 'user.invitation_resent');
    }

    /**
     * The one predicate behind resendInvitation() and the "Kirim ulang undangan" button: an active person who never
     * signed in and administers his home company (holds roles.manage there). Pass the result of administratorIds()
     * for a list of users, so the check costs no query per row.
     *
     * @param  array<int, true>|null  $administrators
     */
    public function canResendInvitation(User $user, ?array $administrators = null): bool
    {
        if (! $user->is_active || $user->last_login_at !== null) {
            return false;
        }

        return $administrators !== null
            ? isset($administrators[$user->id])
            : CompanyService::runAs((int) $user->company_id, fn (): bool => $user->can(PermissionCatalogue::ADMINISTRATION));
    }

    /**
     * The users of this list who hold roles.manage in their HOME company, through one of its roles or directly, in two
     * reads. The platform has no tenant context, so the company of the roles is matched explicitly.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, true> keyed by user id
     */
    public function administratorIds(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();
        $viaRole = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', fn ($join) => $join->on('roles.id', '=', 'model_has_roles.role_id')->on('roles.company_id', '=', 'users.company_id'))
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $ids)
            ->where('permissions.name', PermissionCatalogue::ADMINISTRATION)
            ->pluck('model_has_roles.model_id');
        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->whereIn('model_has_permissions.model_id', $ids)
            ->where('permissions.name', PermissionCatalogue::ADMINISTRATION)
            ->pluck('model_has_permissions.model_id');

        return array_fill_keys($viaRole->merge($direct)->map(fn (mixed $id): int => (int) $id)->all(), true);
    }

    /**
     * Takes every lock that keeps this user from signing in: the password budget of each address the security trail
     * saw for them, and the second-factor budgets of those addresses and of the account. The budget of an address
     * itself (`login-address|ip`) guards the address, not the user, so it stays. The audit rows count the addresses and
     * never name them.
     */
    public function unlockLogin(PlatformAdmin $actor, User $user): void
    {
        $addresses = $this->trailAddresses([$user->id])[$user->id] ?? [];

        foreach ($addresses as $ip) {
            RateLimiter::clear(LoginRequest::throttleKeyFor($user->email, $ip));
            RateLimiter::clear(TwoFactorChallengeRequest::addressKeyFor($user, $ip));
        }

        RateLimiter::clear(TwoFactorChallengeRequest::accountKeyFor($user));
        $this->record($actor, $user, 'user.login_unlocked', ['addresses' => count($addresses)]);
    }

    /**
     * The users of this list who cannot sign in right now, with what keeps them out and for how long: the lock that
     * lasts longest, read from the same limiter keys the sign-in and the challenge use.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{reason: 'password'|'two_factor', seconds: int}> keyed by user id, locked users only
     */
    public function loginLocks(Collection $users): array
    {
        $addresses = $this->trailAddresses($users->pluck('id')->all());
        $locks = [];

        foreach ($users as $user) {
            $budgets = [[TwoFactorChallengeRequest::accountKeyFor($user), TwoFactorChallengeRequest::ATTEMPTS_PER_ACCOUNT, 'two_factor']];

            foreach ($addresses[$user->id] ?? [] as $ip) {
                $budgets[] = [LoginRequest::throttleKeyFor($user->email, $ip), LoginRequest::ATTEMPTS_PER_ACCOUNT, 'password'];
                $budgets[] = [TwoFactorChallengeRequest::addressKeyFor($user, $ip), TwoFactorChallengeRequest::ATTEMPTS_PER_ADDRESS, 'two_factor'];
            }

            $lock = null;

            foreach ($budgets as [$key, $attempts, $reason]) {
                $seconds = RateLimiter::tooManyAttempts($key, $attempts) ? RateLimiter::availableIn($key) : 0;

                if ($seconds > ($lock['seconds'] ?? 0)) {
                    $lock = ['reason' => $reason, 'seconds' => $seconds];
                }
            }

            if ($lock !== null) {
                $locks[$user->id] = $lock;
            }
        }

        return $locks;
    }

    /** @return LengthAwarePaginator<int, array{uuid: mixed, queue: mixed, job: mixed, exception: string, company: array{id: string, code: string}, failed_at: string}> */
    public function failedJobs(Company $root, int $page, int $perPage): LengthAwarePaginator
    {
        $companies = $this->companies($root);
        $companyIds = $companies->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $matches = [];

        foreach (DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->select(['id', 'payload'])
            ->cursor() as $row) {
            $companyId = PlatformTenantListQuery::companyOfFailedJob((string) $row->payload);

            if ($companyId !== null && in_array($companyId, $companyIds, true)) {
                $matches[(int) $row->id] = $companyId;
            }
        }

        $ids = array_slice(array_keys($matches), ($page - 1) * $perPage, $perPage);
        $rows = $ids === []
            ? collect()
            : DB::table('failed_jobs')
                ->whereIn('id', $ids)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->keyBy('id');

        $items = collect($ids)->map(function (int $id) use ($rows, $matches, $companies): array {
            $row = $rows[$id];
            $companyId = $matches[$id];

            return [
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'job' => json_decode((string) $row->payload, true)['displayName'] ?? null,
                'exception' => Str::limit(Str::before((string) $row->exception, "\n"), 200),
                'company' => ['id' => $companies[$companyId]->public_id, 'code' => $companies[$companyId]->code],
                'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ];
        });

        /** @var LengthAwarePaginator<int, array{uuid: mixed, queue: mixed, job: mixed, exception: string, company: array{id: string, code: string}, failed_at: string}> $result */
        $result = new LengthAwarePaginator($items, count($matches), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);

        return $result;
    }

    /** @return LengthAwarePaginator<int, array{id: string, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, company: array{id: string, code: string}, failed_at: string}> */
    public function failedNotifications(Company $root, int $page, int $perPage): LengthAwarePaginator
    {
        $companies = $this->companies($root);

        $result = DB::table('whatsapp_messages')
            ->whereIn('company_id', $companies->keys())
            ->where('status', 'failed')
            ->where('failed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->select(['id', 'public_id', 'company_id', 'event', 'template', 'error_code', 'recipient_last4', 'failed_at'])
            ->paginate($perPage, page: $page)
            ->withQueryString();
        /** @var Collection<int, object{id: mixed, public_id: mixed, company_id: mixed, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, failed_at: mixed}> $rows */
        $rows = collect($result->items());
        $items = $rows->map(fn (object $row): array => [
            'id' => $row->public_id,
            'event' => $row->event,
            'template' => $row->template,
            'error_code' => $row->error_code,
            'recipient_last4' => $row->recipient_last4,
            'company' => ['id' => $companies[$row->company_id]->public_id, 'code' => $companies[$row->company_id]->code],
            'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
        ]);

        /** @var LengthAwarePaginator<int, array{id: string, event: mixed, template: mixed, error_code: mixed, recipient_last4: mixed, company: array{id: string, code: string}, failed_at: string}> $mapped */
        $mapped = new LengthAwarePaginator($items, $result->total(), $result->perPage(), $result->currentPage(), [
            'path' => $result->path(),
            'query' => request()->query(),
        ]);

        return $mapped;
    }

    /** @return Collection<int, Company> */
    private function companies(Company $root): Collection
    {
        return Company::query()
            ->whereKey($root->id)
            ->orWhere('parent_company_id', $root->id)
            ->orderBy('id')
            ->get(['id', 'public_id', 'code'])
            ->keyBy('id');
    }

    /**
     * The client addresses the security trail saw wrong passwords or wrong second-factor codes from, per user id, in the
     * last counting window: one query for the whole list. The trail is how a limiter key that holds an address is found
     * again; a lock from a typed address that named no active account is no user's lock and is not found. A run of wrong
     * passwords only raises the count of the window's first row, so a row counts by its last update.
     * shortcut: no cap on the addresses of one user, so a spray from thousands of addresses makes the page read thousands
     * of limiter keys; cap it when that is seen.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function trailAddresses(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('activity_log')
            ->where('log_name', 'security')
            ->whereIn('description', ['login.failed', 'two_factor.challenge_failed'])
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $userIds)
            ->whereNotNull('ip_address')
            ->where('updated_at', '>=', now()->subSeconds(SecurityTrail::WINDOW_SECONDS))
            ->distinct()
            ->get(['subject_id', 'ip_address'])
            ->groupBy('subject_id')
            ->map(fn (Collection $rows): array => $rows->pluck('ip_address')->all())
            ->all();
    }

    /** @param  array<string, int>  $extra  counts only: both rows, the tenant's included, hold ids and numbers and never an address */
    private function record(PlatformAdmin $actor, User $user, string $action, array $extra = []): void
    {
        $properties = ['user_id' => $user->id, ...$extra];
        $this->audit->platform($action, $properties, $user, $actor);
        $this->audit->support($action, $properties, $user, $actor);
    }
}
