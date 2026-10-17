<?php

namespace App\Queries\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\TenantLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    private const USER_COLUMNS = ['id', 'name', 'email', 'company_id', 'is_active', 'last_login_at', 'two_factor_confirmed_at'];

    public function __construct(
        private readonly PlatformTenantListQuery $tenants,
        private readonly TenantLifecycleService $lifecycle,
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

        return [
            'tenant' => $this->profile($root),
            'metrics' => $this->tenants->metricsFor($root),
            'admins' => $this->userRows($this->admins($ids), $companies),
            'subsidiaries' => $subsidiaries->map(fn (Company $subsidiary): array => [
                'id' => $subsidiary->id,
                'name' => $subsidiary->name,
                'code' => $subsidiary->code,
                'is_active' => $subsidiary->is_active,
                'currency' => $subsidiary->currency,
                'timezone' => $subsidiary->timezone,
                'users_count' => (int) $subsidiary->users_count,
            ])->all(),
            'users' => JsonResource::collection($users),
            'userFilters' => $userFilters,
            'recentAudit' => $this->recentAudit($ids),
            'failedJobs' => $this->failedJobs($ids),
            'failedWhatsApp' => $this->failedWhatsApp($ids, $companies),
            'exports' => $this->exports($root),
            'lifecycle' => $this->lifecycle->state($root),
        ];
    }

    /** @return array<string, mixed> */
    private function profile(Company $root): array
    {
        return [
            'id' => $root->id,
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
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function admins(array $ids): Collection
    {
        return User::query()
            ->select(self::USER_COLUMNS)
            ->whereIn('company_id', $ids)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'admin')->where('guard_name', 'web'))
            ->with('roles:id,name')
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
            ->with('roles:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /**
     * The user row of design §5.2. Impersonation (ticket 04) needs an active user, an active tenant and a company the
     * user can enter: their home, or an active membership when the home is inactive (CompanyService::canEnter).
     * "Invitation pending" is a user who never signed in and was sent a set-password link that is still on file, expired or not.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Company>  $companies  the tenant's companies by id, the head first
     * @return list<array<string, mixed>>
     */
    private function userRows(Collection $users, Collection $companies): array
    {
        $tenantActive = (bool) $companies->first()?->is_active;
        $emails = $users->pluck('email')->all();
        $strandedIds = $users->reject(fn (User $user): bool => $companies[$user->company_id]->is_active)->pluck('id')->all();

        $invited = $emails === [] ? [] : array_flip(DB::table($this->resetTokenTable())->whereIn('email', $emails)->pluck('email')->all());
        $enterable = $strandedIds === [] ? [] : array_flip(DB::table('company_user')
            ->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->whereIn('company_user.user_id', $strandedIds)
            ->where('companies.is_active', true)
            ->pluck('company_user.user_id')
            ->all());

        return $users->map(function (User $user) use ($companies, $tenantActive, $invited, $enterable): array {
            $home = $companies[$user->company_id];

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company' => ['id' => $home->id, 'name' => $home->name, 'code' => $home->code],
                'roles' => $user->roles->pluck('name')->sort()->values()->all(),
                'is_active' => $user->is_active,
                'last_login_at' => Carbon::make($user->last_login_at)?->toIso8601String(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'can_impersonate' => $user->is_active && $tenantActive && ($home->is_active || isset($enterable[$user->id])),
                'invitation_pending' => $user->last_login_at === null && isset($invited[$user->email]),
            ];
        })->values()->all();
    }

    private function resetTokenTable(): string
    {
        return (string) config('auth.passwords.'.config('auth.defaults.passwords').'.table');
    }

    /**
     * The newest rows about this tenant, whoever wrote them (its users, and the platform and support entries about
     * it). No properties dump: only who, what, about which kind of record, and the reason when the row has one.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function recentAudit(array $ids): array
    {
        $rows = DB::table('activity_log')
            ->whereIn('company_id', $ids)
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'log_name', 'description', 'subject_type', 'causer_type', 'causer_id', 'properties', 'created_at']);

        $names = [];

        foreach ([PlatformAdmin::class, User::class] as $causer) {
            $causerIds = $rows->where('causer_type', $causer)->pluck('causer_id')->unique()->all();
            $names[$causer] = $causerIds === [] ? [] : $causer::query()->whereIn('id', $causerIds)->pluck('name', 'id')->all();
        }

        return $rows->map(function (object $row) use ($names): array {
            $properties = json_decode((string) $row->properties, true);
            $kind = match ($row->causer_type) {
                PlatformAdmin::class => 'platform_admin',
                User::class => 'user',
                default => null,
            };

            return [
                'id' => (int) $row->id,
                'log_name' => $row->log_name,
                'description' => $row->description,
                'subject_type' => $row->subject_type === null ? null : class_basename($row->subject_type),
                'causer' => $kind === null ? null : ['type' => $kind, 'name' => $names[$row->causer_type][$row->causer_id] ?? null],
                'reason' => is_array($properties) && is_string($properties['reason'] ?? null) ? $properties['reason'] : null,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
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
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
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
            ->get(['id', 'company_id', 'event', 'template', 'error_code', 'recipient_last4', 'failed_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'event' => $row->event,
                'template' => $row->template,
                'error_code' => $row->error_code,
                'recipient_last4' => $row->recipient_last4,
                'company' => ['id' => (int) $row->company_id, 'code' => $companies[$row->company_id]->code],
                'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The tenant's latest exports. No download link: the download route is signed and checked on the record by the
     * export ticket, which adds it to these rows.
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
                'id' => $export->id,
                'uuid' => $export->uuid,
                'status' => $export->status,
                'file_size' => $export->file_size,
                'row_counts' => $export->row_counts,
                'error_message' => $export->error_message,
                'requested_by' => $export->requestedBy?->name,
                'created_at' => $export->created_at?->toIso8601String(),
                'completed_at' => $export->completed_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
            ])
            ->all();
    }
}
