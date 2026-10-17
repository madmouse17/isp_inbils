<?php

namespace App\Queries\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAuditService;
use App\Support\AuditProperties;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class PlatformAuditQuery
{
    /**
     * @param  array{company_id: ?int, platform_admin_id: ?int, event: ?string, date_from: ?string, date_to: ?string, search: ?string, page: int, per_page: int}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $companyIds = $filters['company_id'] === null ? null : Company::query()
            ->whereKey($filters['company_id'])
            ->orWhere('parent_company_id', $filters['company_id'])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        /** @var LengthAwarePaginator<int, Activity> $page */
        $page = Activity::query()
            ->select(['id', 'public_id', 'company_id', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'batch_uuid', 'created_at'])
            ->whereIn('log_name', [PlatformAuditService::PLATFORM_LOG, PlatformAuditService::SUPPORT_LOG])
            ->when($companyIds !== null, fn (Builder $query) => $query->whereIn('company_id', $companyIds))
            ->when($filters['platform_admin_id'], fn (Builder $query, int $id) => $query
                ->where('causer_type', PlatformAdmin::class)
                ->where('causer_id', $id))
            ->when($filters['event'], fn (Builder $query, string $event) => $query->where('description', $event))
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->where('created_at', '>=', Carbon::parse($date, 'UTC')->startOfDay()))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query->where('created_at', '<', Carbon::parse($date, 'UTC')->addDay()->startOfDay()))
            ->when($filters['search'], fn (Builder $query, string $term) => $this->search($query, $term))
            ->with('causer')
            ->orderByDesc('id')
            ->paginate($filters['per_page'], page: $filters['page'])
            ->withQueryString();

        $activities = collect($page->items());
        $companies = Company::query()
            ->whereIn('id', $activities->pluck('company_id')->filter()->unique())
            ->get(['id', 'public_id', 'name', 'code'])
            ->keyBy('id');
        $subjects = $this->subjectPublicIds($activities);

        /** @var LengthAwarePaginator<int, array<string, mixed>> $result */
        $result = new LengthAwarePaginator(
            $activities->map(fn (Activity $activity): array => $this->row($activity, $companies, $subjects)),
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            ['path' => $page->path(), 'query' => request()->query()],
        );

        return $result;
    }

    /**
     * The filters as the page shows them: the company and the admin by public id.
     *
     * @param  array{company_id: ?int, platform_admin_id: ?int, event: ?string, date_from: ?string, date_to: ?string, search: ?string, page: int, per_page: int}  $filters
     * @return array<string, mixed>
     */
    public function displayFilters(array $filters): array
    {
        return [
            ...$filters,
            'company_id' => $filters['company_id'] === null ? null : Company::query()->whereKey($filters['company_id'])->value('public_id'),
            'platform_admin_id' => $filters['platform_admin_id'] === null ? null : PlatformAdmin::query()->whereKey($filters['platform_admin_id'])->value('public_id'),
        ];
    }

    /** @return list<array{id: string, name: string, code: string}> */
    public function tenants(): array
    {
        return Company::query()
            ->whereNull('parent_company_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name', 'code'])
            ->map(fn (Company $company): array => ['id' => $company->public_id, 'name' => $company->name, 'code' => $company->code])
            ->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function admins(): array
    {
        return PlatformAdmin::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name'])
            ->map(fn (PlatformAdmin $admin): array => ['id' => $admin->public_id, 'name' => $admin->name])
            ->all();
    }

    /** @param Builder<Activity> $query */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $match) use ($like): void {
            $match->where('description', 'like', $like)
                ->orWhereIn('causer_id', PlatformAdmin::query()
                    ->select('id')
                    ->where(fn (Builder $admins) => $admins->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->orWhereIn('company_id', Company::query()
                    ->select('id')
                    ->where(fn (Builder $companies) => $companies->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orWhere('properties->tenant_name', 'like', $like)
                ->orWhere('properties->tenant_code', 'like', $like);
        });
    }

    /**
     * The public id of the record each entry is about, read once per type. A type that is no model, or whose table has
     * no public id, names nothing.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, string> "type:id" => public id
     */
    private function subjectPublicIds(Collection $activities): array
    {
        $models = $activities
            ->pluck('subject_type')
            ->unique()
            ->filter(fn (mixed $type): bool => is_string($type) && is_subclass_of($type, Model::class) && Schema::hasColumn((new $type())->getTable(), 'public_id'))
            ->mapWithKeys(fn (string $type): array => [$type => $type])
            ->all();

        return PublicId::ofPairs($models, $activities->map(fn (Activity $activity): array => [$activity->subject_type, $activity->subject_id]));
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @param  array<string, string>  $subjects
     * @return array<string, mixed>
     */
    private function row(Activity $activity, Collection $companies, array $subjects): array
    {
        $properties = AuditProperties::forDisplay($activity->properties?->all() ?? []);
        $companyId = $activity->getAttribute('company_id');
        /** @var Company|null $company */
        $company = $companyId === null ? null : $companies->get($companyId);
        $causer = $activity->causer;

        return [
            'id' => $activity->public_id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'company' => $company === null
                ? (isset($properties['tenant_code']) ? ['id' => null, 'name' => $properties['tenant_name'] ?? null, 'code' => $properties['tenant_code']] : null)
                : ['id' => $company->public_id, 'name' => $company->name, 'code' => $company->code],
            'causer' => $causer === null ? null : [
                'type' => $causer instanceof PlatformAdmin ? 'platform_admin' : 'user',
                'id' => $causer->getAttribute('public_id'),
                'name' => $causer->getAttribute('name'),
            ],
            'subject' => $activity->subject_type === null ? null : [
                'type' => class_basename($activity->subject_type),
                'id' => $subjects[$activity->subject_type.':'.$activity->subject_id] ?? null,
            ],
            'properties' => $properties,
            'batch_uuid' => $activity->batch_uuid,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
