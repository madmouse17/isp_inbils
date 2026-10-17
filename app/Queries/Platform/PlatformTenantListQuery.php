<?php

namespace App\Queries\Platform;

use App\Models\Core\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The platform's tenant list: one row per top-level company, with its subsidiaries rolled in. Platform code is the
 * one place that reads rows of every tenant, and it does so the explicit way: the page's tenant ids are resolved
 * first (root plus children), then each metric is ONE grouped query over `whereIn(company_id, those ids)`, so the
 * number of queries does not grow with the number of tenants.
 *
 * Metrics are best-effort where the data is: storage comes from media metadata (`custom_properties.company_id`, so
 * legacy files without it are not counted) and failed jobs are attributed by the `companyId` the app wrote into the
 * job; a failed job without one is unattributed.
 */
class PlatformTenantListQuery
{
    public const SORTABLE = ['name', 'code', 'created_at'];

    public const STATUSES = ['active', 'suspended', 'deletion_requested'];

    /** @var array{byCompany: array<int, int>, unattributed: int}|null */
    private ?array $failedJobs = null;

    /**
     * @param  array{search: ?string, status: ?string, sort: string, direction: string, per_page: int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, Company> $roots */
        $roots = Company::query()
            ->whereNull('parent_company_id')
            ->withCount('children')
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(
                fn (Builder $match) => $match->where('name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%'),
            ))
            ->when($filters['status'], fn (Builder $query, string $status) => $this->whereStatus($query, $status))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $metrics = $this->metrics($roots->getCollection());

        return $roots->through(fn (Company $root): array => [
            'id' => $root->public_id,
            'name' => $root->name,
            'code' => $root->code,
            'status' => self::statusOf($root),
            ...$this->metricRow($root, $metrics),
        ]);
    }

    /**
     * The metrics of one tenant: the numbers of its row in the list, so the detail page and the list agree.
     *
     * @return array{subsidiaries_count: int, users_count: int, customers_count: int, last_login_at: ?string, storage_bytes: int, failed_jobs_7d: int, failed_whatsapp_7d: int}
     */
    public function metricsFor(Company $root): array
    {
        return $this->metricRow($root->loadCount('children'), $this->metrics(new Collection([$root])));
    }

    /** The company a failed job belongs to: the `companyId` the app wrote into the serialized job, or null when it has none. */
    public static function companyOfFailedJob(string $payload): ?int
    {
        $command = (string) (json_decode($payload, true)['data']['command'] ?? '');

        return preg_match('/companyId";i:(\d+);/', $command, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return array{active: int, suspended: int, deletion_requested: int, unattributed_failed_jobs_7d: int} */
    public function summary(): array
    {
        $counts = Company::query()
            ->whereNull('parent_company_id')
            ->selectRaw('COALESCE(SUM(deletion_requested_at IS NOT NULL), 0) AS deletion_requested')
            ->selectRaw('COALESCE(SUM(deletion_requested_at IS NULL AND is_active = 0), 0) AS suspended')
            ->selectRaw('COALESCE(SUM(deletion_requested_at IS NULL AND is_active = 1), 0) AS active')
            ->toBase()
            ->first();

        return [
            'active' => (int) $counts->active,
            'suspended' => (int) $counts->suspended,
            'deletion_requested' => (int) $counts->deletion_requested,
            'unattributed_failed_jobs_7d' => $this->failedJobsLastWeek()['unattributed'],
        ];
    }

    public static function statusOf(Company $company): string
    {
        return match (true) {
            $company->deletion_requested_at !== null => 'deletion_requested',
            ! $company->is_active => 'suspended',
            default => 'active',
        };
    }

    /** @param  Builder<Company>  $query */
    private function whereStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'deletion_requested' => $query->whereNotNull('deletion_requested_at'),
            'suspended' => $query->whereNull('deletion_requested_at')->where('is_active', false),
            default => $query->whereNull('deletion_requested_at')->where('is_active', true),
        };
    }

    /**
     * @param  Collection<int, Company>  $roots
     * @return array{users: array<int, int>, customers: array<int, int>, last_login: array<int, string>, storage: array<int, int>, failed_jobs: array<int, int>, failed_whatsapp: array<int, int>}
     */
    private function metrics(Collection $roots): array
    {
        $metrics = ['users' => [], 'customers' => [], 'last_login' => [], 'storage' => [], 'failed_jobs' => [], 'failed_whatsapp' => []];

        if ($roots->isEmpty()) {
            return $metrics;
        }

        $rootIds = $roots->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        // company id => the root it rolls up to: the roots themselves and their subsidiaries.
        $rootOf = array_combine($rootIds, $rootIds) + Company::query()
            ->whereIn('parent_company_id', $rootIds)
            ->pluck('parent_company_id', 'id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $ids = array_keys($rootOf);

        foreach (DB::table('users')->whereIn('company_id', $ids)->selectRaw('company_id, COUNT(*) AS total, MAX(last_login_at) AS last_login_at')->groupBy('company_id')->get() as $row) {
            $root = $rootOf[(int) $row->company_id];
            $metrics['users'][$root] = ($metrics['users'][$root] ?? 0) + (int) $row->total;

            if ($row->last_login_at !== null && (! isset($metrics['last_login'][$root]) || $row->last_login_at > $metrics['last_login'][$root])) {
                $metrics['last_login'][$root] = $row->last_login_at;
            }
        }

        $metrics['last_login'] = array_map(fn (string $at): string => Carbon::parse($at)->toIso8601String(), $metrics['last_login']);

        $this->sumByRoot($metrics['customers'], $rootOf, DB::table('customers')->whereIn('company_id', $ids)->whereNull('deleted_at')->selectRaw('company_id, COUNT(*) AS total')->groupBy('company_id')->pluck('total', 'company_id')->all());
        $this->sumByRoot($metrics['failed_whatsapp'], $rootOf, DB::table('whatsapp_messages')->whereIn('company_id', $ids)->where('status', 'failed')->where('failed_at', '>=', now()->subDays(7))->selectRaw('company_id, COUNT(*) AS total')->groupBy('company_id')->pluck('total', 'company_id')->all());

        $company = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_properties, \'$.company_id\')) AS UNSIGNED)';
        $this->sumByRoot($metrics['storage'], $rootOf, DB::table('media')->whereIn(DB::raw($company), $ids)->selectRaw("{$company} AS company_id, COALESCE(SUM(size), 0) AS total")->groupBy('company_id')->pluck('total', 'company_id')->all());

        $this->sumByRoot($metrics['failed_jobs'], $rootOf, array_intersect_key($this->failedJobsLastWeek()['byCompany'], $rootOf));

        return $metrics;
    }

    /**
     * @param  array{users: array<int, int>, customers: array<int, int>, last_login: array<int, string>, storage: array<int, int>, failed_jobs: array<int, int>, failed_whatsapp: array<int, int>}  $metrics
     * @return array{subsidiaries_count: int, users_count: int, customers_count: int, last_login_at: ?string, storage_bytes: int, failed_jobs_7d: int, failed_whatsapp_7d: int}
     */
    private function metricRow(Company $root, array $metrics): array
    {
        return [
            'subsidiaries_count' => (int) $root->children_count,
            'users_count' => $metrics['users'][$root->id] ?? 0,
            'customers_count' => $metrics['customers'][$root->id] ?? 0,
            'last_login_at' => $metrics['last_login'][$root->id] ?? null,
            'storage_bytes' => $metrics['storage'][$root->id] ?? 0,
            'failed_jobs_7d' => $metrics['failed_jobs'][$root->id] ?? 0,
            'failed_whatsapp_7d' => $metrics['failed_whatsapp'][$root->id] ?? 0,
        ];
    }

    /**
     * @param  array<int, int>  $totals
     * @param  array<int, int>  $rootOf
     * @param  array<int|string, mixed>  $byCompany
     */
    private function sumByRoot(array &$totals, array $rootOf, array $byCompany): void
    {
        foreach ($byCompany as $companyId => $total) {
            $root = $rootOf[(int) $companyId];
            $totals[$root] = ($totals[$root] ?? 0) + (int) $total;
        }
    }

    /**
     * Failed jobs of the last 7 days by the `companyId` the job carries (a public property the app's jobs write),
     * read once per request from the serialized command. shortcut: one scan of a week of failed rows, no index on
     * the company; upgrade to a company column on failed jobs if the table grows beyond what a scan can carry.
     *
     * @return array{byCompany: array<int, int>, unattributed: int}
     */
    private function failedJobsLastWeek(): array
    {
        if ($this->failedJobs !== null) {
            return $this->failedJobs;
        }

        $byCompany = [];
        $unattributed = 0;

        foreach (DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->select('payload')->cursor() as $row) {
            $companyId = self::companyOfFailedJob((string) $row->payload);

            if ($companyId === null) {
                $unattributed++;
            } else {
                $byCompany[$companyId] = ($byCompany[$companyId] ?? 0) + 1;
            }
        }

        return $this->failedJobs = ['byCompany' => $byCompany, 'unattributed' => $unattributed];
    }
}
