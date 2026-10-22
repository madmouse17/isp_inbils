<?php

namespace App\Queries\Platform;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\SubsidiaryRequestService;
use App\Services\Platform\SupportToolsService;
use App\Services\Platform\TenantExportService;
use App\Services\Platform\TenantLifecycleService;
use App\Support\AuditEventText;
use App\Support\PermissionCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything the platform's tenant page shows about ONE tenant (a head company with its subsidiaries). Like the
 * list, it reads the tenant's rows the explicit way: the ids of the head and its subsidiaries are resolved first and
 * every list is `whereIn(company_id, those ids)`, so one tenant never shows another's rows. Each list is capped
 * (recent rows) or paginated (users), and each is read in a fixed number of queries whatever the rows hold: the
 * per-row facts (roles, pending invitation, who may be impersonated, who did what) are looked up in bulk.
 */
class TenantDetailQuery
{
    /** Rows of each "recent ..." list. */
    private const RECENT = 10;

    /** The admin summary is the first admins by name; the paginated user list holds everyone. */
    private const ADMINS = 25;

    private const USER_COLUMNS = ['id', 'public_id', 'name', 'email', 'company_id', 'is_active', 'created_at', 'last_login_at', 'two_factor_confirmed_at'];

    public function __construct(
        private readonly PlatformTenantListQuery $tenants,
        private readonly TenantLifecycleService $lifecycle,
        private readonly SupportToolsService $support,
        private readonly TenantExportService $exports,
        private readonly SubsidiaryRequestService $subsidiaryRequests,
    ) {}

    /**
     * @param  array{search: ?string, status: ?string, per_page: int}  $userFilters
     * @return array<string, mixed>
     */
    public function props(Company $root, array $userFilters): array
    {
        $subsidiaries = Company::query()->where('parent_company_id', $root->id)->withCount('users')->orderBy('name')->orderBy('id')->get();
        $companies = collect([$root])->merge($subsidiaries)->keyBy('id');
        $ids = $companies->keys()->map(fn (mixed $id): int => (int) $id)->all();

        $users = $this->users($ids, $userFilters);
        /** @var LengthAwarePaginator<int, mixed> $page the rows are arrays from here on */
        $page = $users;
        $page->setCollection(collect($this->userRows($users->getCollection(), $companies)));

        $lifecycle = $this->lifecycle->state($root);

        return [
            'tenant' => $this->profile($root),
            'metrics' => $this->tenants->metricsFor($root),
            'admins' => $this->userRows($this->admins($ids), $companies),
            'subsidiaries' => $subsidiaries->map(fn (Company $subsidiary): array => [
                'id' => $subsidiary->public_id,
                'name' => $subsidiary->name,
                'code' => $subsidiary->code,
                'is_active' => $subsidiary->is_active,
                'currency' => $subsidiary->currency,
                'timezone' => $subsidiary->timezone,
                'users_count' => (int) $subsidiary->users_count,
            ])->all(),
            'users' => JsonResource::collection($users),
            'companyRoles' => $this->companyRoles($companies),
            'roleTemplates' => RoleTemplate::query()->orderBy('id')->get(['key', 'name'])->map(fn (RoleTemplate $template): array => ['key' => $template->key, 'name' => $template->name])->all(),
            'userFilters' => $userFilters,
            'recentAudit' => $this->recentAudit($ids),
            'subsidiaryRequests' => $this->subsidiaryRequests->pending($root),
            'failedJobs' => $this->failedJobs($ids),
            'failedWhatsApp' => $this->failedWhatsApp($ids, $companies),
            'integrations' => $this->integrations($ids, $companies),
            'exports' => $this->exports($root),
            'lifecycle' => $lifecycle,
            'suspendImpact' => $lifecycle['can_suspend'] ? $this->suspendImpact($ids) : null,
            'sessionDriver' => [
                'ends_all' => config('session.driver') === 'database',
                'lifetime_minutes' => (int) config('session.lifetime'),
            ],
        ];
    }

    /**
     * Every company of the tenant × every provider, with the state of its row ("empty" when it has none). The columns
     * read exclude `credentials`, `settings` and `external_id`: a state and a time leave the server, never a value.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Company>  $companies
     * @return list<array<string, mixed>>
     */
    private function integrations(array $ids, Collection $companies): array
    {
        $rows = CompanyIntegration::withoutCompany()
            ->whereIn('company_id', $ids)
            ->get(['id', 'company_id', 'provider', 'is_enabled', 'verified_at', 'last_error', 'last_checked_at'])
            ->keyBy(fn (CompanyIntegration $row): string => $row->company_id.':'.$row->provider);

        return $companies->flatMap(fn (Company $company): array => array_map(function (string $provider) use ($company, $rows): array {
            $row = $rows->get($company->id.':'.$provider);

            return [
                'company' => ['id' => $company->public_id, 'code' => $company->code, 'name' => $company->name],
                'provider' => $provider,
                'state' => CompanyIntegration::stateOf($row),
                'is_enabled' => (bool) $row?->is_enabled,
                'last_checked_at' => $row?->last_checked_at?->toIso8601String(),
            ];
        }, CompanyIntegration::PROVIDERS))->values()->all();
    }

    /** @return array<string, mixed> */
    private function profile(Company $root): array
    {
        return [
            'id' => $root->public_id,
            'name' => $root->name,
            'code' => $root->code,
            'status' => PlatformTenantListQuery::statusOf($root),
            'email' => $root->email,
            'phone' => $root->phone,
            'website' => $root->website,
            'address' => $root->address,
            'timezone' => $root->timezone,
            'currency' => $root->currency,
            'suspended_at' => $root->suspended_at?->toIso8601String(),
            'deletion_requested_at' => $root->deletion_requested_at?->toIso8601String(),
            'created_at' => $root->created_at?->toIso8601String(),
        ];
    }

    /**
     * The people who administer a company of this tenant: users whose roles IN THEIR HOME COMPANY hold roles.manage. The
     * platform has no tenant context, so the roles are matched on the home company explicitly.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function admins(array $ids): Collection
    {
        return User::query()
            ->select(self::USER_COLUMNS)
            ->whereIn('company_id', $ids)
            ->whereExists(fn ($roles) => $roles->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->whereColumn('roles.company_id', 'users.company_id')
                ->where('permissions.name', PermissionCatalogue::ADMINISTRATION))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::ADMINS)
            ->get();
    }

    /**
     * @param  list<int>  $ids
     * @param  array{search: ?string, status: ?string, per_page: int}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    private function users(array $ids, array $filters): LengthAwarePaginator
    {
        return User::query()
            ->select(self::USER_COLUMNS)
            ->whereIn('company_id', $ids)
            ->when($filters['search'], function (Builder $query, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /**
     * The user row of design §5.2. Impersonation (ticket 04) needs an active user, an active tenant and a company the
     * user can enter: their home, or an active membership when the home is inactive (CompanyService::canEnter).
     * The support tools add what stops a person from signing in (`login_lock`) and where the set-password mail of
     * someone who never signed in stands (`invitation`), both read in bulk for the rows of the list.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Company>  $companies  the tenant's companies by id, the head first
     * @return list<array<string, mixed>>
     */
    private function userRows(Collection $users, Collection $companies): array
    {
        $tenantActive = (bool) $companies->first()?->is_active;
        $strandedIds = $users->reject(fn (User $user): bool => $companies[$user->company_id]->is_active)->pluck('id')->all();

        $enterable = $strandedIds === [] ? [] : array_flip(DB::table('company_user')
            ->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->whereIn('company_user.user_id', $strandedIds)
            ->where('companies.is_active', true)
            ->pluck('company_user.user_id')
            ->all());
        $administrators = $this->support->administratorIds($users);
        $invitations = $this->invitations($users, $administrators);
        $locks = $this->support->loginLocks($users);
        $roles = $this->homeRoleNames($users);

        return $users->map(function (User $user) use ($companies, $tenantActive, $enterable, $invitations, $locks, $administrators, $roles): array {
            $home = $companies[$user->company_id];

            return [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'company' => ['id' => $home->public_id, 'name' => $home->name, 'code' => $home->code],
                'roles' => $roles[$user->id] ?? [],
                'is_active' => $user->is_active,
                'last_login_at' => Carbon::make($user->last_login_at)?->toIso8601String(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'can_impersonate' => $user->is_active && $tenantActive && ($home->is_active || isset($enterable[$user->id])),
                'login_lock' => $locks[$user->id] ?? null,
                'invitation' => $invitations[$user->id] ?? null,
                'can_resend_invitation' => $this->support->canResendInvitation($user, $administrators),
            ];
        })->values()->all();
    }

    /**
     * The role names each user holds in his HOME company, by user id, in one read. A user may also hold roles in the
     * companies he is a member of; the tenant page lists the home company's, as the company column says.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<string>>
     */
    private function homeRoleNames(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $users->pluck('id')->all())
            ->whereColumn('roles.company_id', 'users.company_id')
            ->orderBy('roles.name')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id')
            ->map(fn (Collection $rows): array => $rows->pluck('name')->all())
            ->all();
    }

    /**
     * Every company of the tenant with its roles, the head first: the repair table of the tenant page. Counts are
     * subqueries, so one read serves the whole tenant.
     *
     * @param  Collection<int, Company>  $companies  the tenant's companies by id, the head first
     * @return list<array<string, mixed>>
     */
    private function companyRoles(Collection $companies): array
    {
        $rows = DB::table('roles')
            ->whereIn('company_id', $companies->keys()->all())
            ->where('guard_name', 'web')
            ->select(['public_id', 'company_id', 'name', 'template_key'])
            ->selectSub(DB::table('role_has_permissions')->whereColumn('role_has_permissions.role_id', 'roles.id')->selectRaw('count(*)'), 'permissions_count')
            ->selectSub(DB::table('model_has_roles')->whereColumn('model_has_roles.role_id', 'roles.id')->where('model_type', User::class)->selectRaw('count(*)'), 'users_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy('company_id');

        return $companies->map(fn (Company $company): array => [
            'company' => ['id' => $company->public_id, 'name' => $company->name, 'code' => $company->code],
            'roles' => ($rows[$company->id] ?? collect())->map(fn (object $role): array => [
                'id' => $role->public_id,
                'name' => $role->name,
                'template_key' => $role->template_key,
                'permissions_count' => (int) $role->permissions_count,
                'users_count' => (int) $role->users_count,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Where the invitation of each user who never signed in stands, by user id; the rest are left out. In this order:
     * a failure row newer than the reset token says `failed`; a token says `sent`, or `expired` once it is older than
     * the broker lets it live; no token and no failure says `queued` for a user an invitation can still go to (an
     * active admin), since the mail may be waiting for a queue worker. Two reads whatever the list holds: the tokens
     * and the invitation rows of the platform log.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, true>  $administrators  SupportToolsService::administratorIds() of the same users
     * @return array<int, array{state: 'queued'|'sent'|'expired'|'failed', at: string}>
     */
    private function invitations(Collection $users, array $administrators): array
    {
        $waiting = $users->filter(fn (User $user): bool => $user->last_login_at === null);

        if ($waiting->isEmpty()) {
            return [];
        }

        $tokens = DB::table((string) $this->resetTokenConfig('table'))->whereIn('email', $waiting->pluck('email')->all())->pluck('created_at', 'email');
        $latest = DB::table('activity_log')
            ->where('log_name', PlatformAuditService::PLATFORM_LOG)
            ->whereIn('description', ['user.invitation_failed', 'user.invitation_resent'])
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $waiting->pluck('id')->all())
            ->groupBy('subject_id', 'description')
            ->get(['subject_id', 'description', DB::raw('MAX(created_at) AS latest_at')])
            ->mapWithKeys(fn (object $row): array => [$row->subject_id.'|'.$row->description => Carbon::make($row->latest_at)]);
        $lifetime = (int) $this->resetTokenConfig('expire');
        $invitations = [];

        foreach ($waiting as $user) {
            $token = Carbon::make($tokens[$user->email] ?? null);
            $failed = $latest[$user->id.'|user.invitation_failed'] ?? null;
            $queuedAt = collect([Carbon::make($user->created_at), $latest[$user->id.'|user.invitation_resent'] ?? null])->filter()->max();

            $invitation = match (true) {
                $failed !== null && ($token === null || $failed->gte($token)) => ['failed', $failed],
                $token !== null => [$token->copy()->addMinutes($lifetime)->isPast() ? 'expired' : 'sent', $token],
                $queuedAt !== null && $this->support->canResendInvitation($user, $administrators) => ['queued', $queuedAt],
                default => null,
            };

            if ($invitation !== null) {
                $invitations[$user->id] = ['state' => $invitation[0], 'at' => $invitation[1]->toIso8601String()];
            }
        }

        return $invitations;
    }

    private function resetTokenConfig(string $key): mixed
    {
        return config('auth.passwords.'.config('auth.defaults.passwords').'.'.$key);
    }

    /**
     * What suspending the tenant would cut off, counted for its confirmation dialog: the people who could still sign in,
     * the sessions and app tokens they hold, and the invoices still waiting for money. Sessions are only countable on the
     * database driver (null elsewhere). A count, never a list of people.
     *
     * @param  list<int>  $ids
     * @return array{users: int, sessions: int|null, app_tokens: int, open_invoices: array{count: int, outstanding: string}}
     */
    private function suspendImpact(array $ids): array
    {
        $users = DB::table('users')->whereIn('company_id', $ids)->where('is_active', true)->select('id');
        $invoices = DB::table('invoices')
            ->whereIn('company_id', $ids)
            ->whereNull('deleted_at')
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->selectRaw('count(*) as total_count, coalesce(sum(total - paid_amount), 0) as outstanding')
            ->first();

        return [
            'users' => (clone $users)->count(),
            'sessions' => config('session.driver') === 'database'
                ? DB::table((string) config('session.table', 'sessions'))
                    ->whereIn('user_id', (clone $users))
                    ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                    ->count()
                : null,
            'app_tokens' => DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', (clone $users))
                ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'open_invoices' => [
                'count' => (int) ($invoices->total_count ?? 0),
                'outstanding' => (string) ($invoices->outstanding ?? '0'),
            ],
        ];
    }

    /**
     * The newest rows about this tenant, whoever wrote them (its users, and the platform and support entries about
     * it). No properties dump: the sentence of the row without its details, who, what kind of record, and the reason
     * when the row has one.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function recentAudit(array $ids): array
    {
        /** @var EloquentCollection<int, Activity> $rows */
        $rows = Activity::query()
            ->select(['id', 'public_id', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'created_at'])
            ->with('causer')
            ->whereIn('company_id', $ids)
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();

        // Loads the subjects too: the sentence of a support row names the person it was about.
        AuditEventText::annotate($rows);

        return $rows->map(function (Activity $row): array {
            $kind = match ($row->causer_type) {
                PlatformAdmin::class => 'platform_admin',
                User::class => 'user',
                default => null,
            };
            $reason = $row->properties?->get('reason');

            return [
                'id' => $row->public_id,
                'log_name' => $row->log_name,
                'description' => $row->description,
                'text' => AuditEventText::text($row, withProperties: false),
                'subject_type' => $row->subject_type === null ? null : class_basename($row->subject_type),
                'causer' => $kind === null ? null : ['type' => $kind, 'name' => $row->causer?->getAttribute('name')],
                'reason' => is_string($reason) ? $reason : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * The newest failed jobs of the last 7 days that carry one of these companies' ids (see
     * PlatformTenantListQuery::companyOfFailedJob), without their payloads. The week's payloads are scanned newest
     * first and the scan stops at the tenth match. shortcut: a tenant with fewer failures scans the whole week, the
     * way the list's metric does; upgrade both to a company column on failed jobs.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function failedJobs(array $ids): array
    {
        $found = [];
        $week = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->orderByDesc('id')->select(['id', 'payload'])->cursor();

        foreach ($week as $row) {
            if (in_array(PlatformTenantListQuery::companyOfFailedJob((string) $row->payload), $ids, true)) {
                $found[] = (int) $row->id;
            }

            if (count($found) === self::RECENT) {
                break;
            }
        }

        if ($found === []) {
            return [];
        }

        return DB::table('failed_jobs')
            ->whereIn('id', $found)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (object $row): array => [
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'job' => json_decode((string) $row->payload, true)['displayName'] ?? null,
                'exception' => Str::limit(Str::before((string) $row->exception, "\n"), 200),
                'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, Company>  $companies
     * @return list<array<string, mixed>>
     */
    private function failedWhatsApp(array $ids, Collection $companies): array
    {
        return DB::table('whatsapp_messages')
            ->whereIn('company_id', $ids)
            ->where('status', 'failed')
            ->where('failed_at', '>=', now()->subDays(7))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'public_id', 'company_id', 'event', 'template', 'error_code', 'recipient_last4', 'failed_at'])
            ->map(fn (object $row): array => [
                'id' => $row->public_id,
                'event' => $row->event,
                'template' => $row->template,
                'error_code' => $row->error_code,
                'recipient_last4' => $row->recipient_last4,
                'company' => ['id' => $companies[$row->company_id]->public_id, 'code' => $companies[$row->company_id]->code],
                'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The tenant's latest exports, each with the signed link while it can still be downloaded (the same link and the
     * same rule as the exports page, so the download route checks it on the record and writes the audit rows).
     *
     * @return list<array<string, mixed>>
     */
    private function exports(Company $root): array
    {
        return TenantExport::query()
            ->with('requestedBy:id,name')
            ->where('company_id', $root->id)
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(fn (TenantExport $export): array => [
                'id' => $export->public_id,
                'uuid' => $export->uuid,
                'status' => $export->status,
                'file_size' => $export->file_size,
                'row_counts' => $export->row_counts,
                'error_message' => $export->error_message,
                'requested_by' => $export->requestedBy?->name,
                'created_at' => $export->created_at?->toIso8601String(),
                'completed_at' => $export->completed_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
                'download_url' => $this->exports->isDownloadable($export)
                    ? URL::temporarySignedRoute('platform.exports.download', $export->expires_at, ['tenantExport' => $export])
                    : null,
            ])
            ->all();
    }
}
