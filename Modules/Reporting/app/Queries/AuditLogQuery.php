<?php

namespace Modules\Reporting\Queries;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Spatie\Activitylog\Models\Activity;

class AuditLogQuery
{
    use AppliesDateRange;

    /**
     * Build base query with mandatory company scoping and filters.
     *
     * @return Builder<Activity>
     */
    public static function query(
        ?int $userId = null,
        ?string $logName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        ?string $event = null,
        ?int $companyId = null
    ): Builder {
        $actor = auth()->user();
        $authCompanyId = $actor instanceof User ? CompanyService::idFor($actor) : null;
        $contextCompanyId = CompanyService::currentId();

        if ($authCompanyId !== null) {
            if ($companyId !== null && (int) $companyId !== (int) $authCompanyId) {
                abort(403, 'Kueri lintas perusahaan tidak diizinkan.');
            }
            $resolvedCompanyId = (int) $authCompanyId;
        } elseif ($contextCompanyId !== null) {
            if ($companyId !== null && (int) $companyId !== (int) $contextCompanyId) {
                abort(403, 'Kueri lintas perusahaan tidak diizinkan.');
            }
            $resolvedCompanyId = (int) $contextCompanyId;
        } else {
            $resolvedCompanyId = $companyId;
        }

        abort_if($resolvedCompanyId === null, 403, 'Company context is required.');

        $query = Activity::query()->where('company_id', (int) $resolvedCompanyId);

        if ($userId) {
            // A member, as the report's picker lists them: a user homed elsewhere but granted this company acts here too.
            $userBelongsToCompany = DB::table('company_user')
                ->where('company_id', (int) $resolvedCompanyId)
                ->where('user_id', $userId)
                ->exists();

            if (! $userBelongsToCompany) {
                $query->whereKey([]);
            } else {
                $query->where('causer_id', $userId)->where('causer_type', 'App\\Models\\User');
            }
        }

        if ($logName) {
            $query->where('log_name', $logName);
        }

        if ($event) {
            $query->where('event', $event);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $sub) use ($term, $resolvedCompanyId) {
                $sub->where('description', 'like', "%{$term}%")
                    ->orWhere('log_name', 'like', "%{$term}%")
                    ->orWhere('event', 'like', "%{$term}%")
                    ->orWhere(function (Builder $causerSub) use ($term, $resolvedCompanyId) {
                        $causerSub->where('causer_type', 'App\\Models\\User')
                            ->whereIn('causer_id', function ($userQuery) use ($term, $resolvedCompanyId) {
                                $userQuery->select('id')
                                    ->from('users')
                                    ->whereIn('id', fn ($members) => $members->select('user_id')->from('company_user')->where('company_id', (int) $resolvedCompanyId))
                                    ->where(function ($uq) use ($term) {
                                        $uq->where('name', 'like', "%{$term}%")
                                            ->orWhere('email', 'like', "%{$term}%");
                                    });
                            });
                    });
            });
        }

        self::applyDateRange($query, 'created_at', $dateFrom, $dateTo);

        return $query;
    }

    /**
     * Legacy/direct execute returning array payload.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function execute(
        ?int $userId = null,
        ?string $logName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $limit = 500,
        ?int $companyId = null,
        ?string $search = null,
        ?string $event = null
    ): array {
        $query = self::query(
            userId: $userId,
            logName: $logName,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            search: $search,
            event: $event,
            companyId: $companyId
        );

        $canViewProperties = auth()->user()?->can('report.audit.view') ?? false;

        return $query->with(['causer'])->latest('id')->limit($limit ?? 500)->get()->map(function (Activity $a) use ($canViewProperties) {
            $causer = $a->causer;

            return [
                'id' => $a->id,
                'company_id' => $a->getAttribute('company_id'),
                'log_name' => $a->log_name,
                'description' => $a->description,
                'event' => $a->event,
                'causer_id' => $a->causer_id,
                'causer_type' => $a->causer_type,
                'causer' => $causer instanceof User ? [
                    'id' => $causer->id,
                    'name' => $causer->name,
                    'email' => $causer->email,
                ] : null,
                'subject_type' => $a->subject_type,
                'subject_id' => $a->subject_id,
                'properties' => $canViewProperties ? ($a->properties ? $a->properties->toArray() : []) : [],
                'created_at' => $a->created_at?->toISOString() ?? (string) $a->created_at,
            ];
        })->toArray();
    }

    /**
     * The filtered, company-scoped rows of the audit table in display order with their actor: what
     * the page pages through and the export streams.
     *
     * @return Builder<Activity>
     */
    public static function listing(
        ?int $userId = null,
        ?string $logName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        ?string $event = null,
        ?int $companyId = null,
        string $sortField = 'created_at',
        string $sortDirection = 'desc'
    ): Builder {
        $allowedSortFields = ['id', 'created_at', 'log_name', 'event', 'description'];
        $field = in_array($sortField, $allowedSortFields, true) ? $sortField : 'created_at';
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $query = self::query(
            userId: $userId,
            logName: $logName,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            search: $search,
            event: $event,
            companyId: $companyId
        )
            ->with(['causer'])
            ->orderBy($field, $direction);

        if ($field !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }

    /**
     * Paginated query for server-side audit tables.
     */
    public static function paginate(
        ?int $userId = null,
        ?string $logName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        ?string $event = null,
        int $perPage = 25,
        ?int $companyId = null,
        string $sortField = 'created_at',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        return self::listing($userId, $logName, $dateFrom, $dateTo, $search, $event, $companyId, $sortField, $sortDirection)
            ->paginate($perPage)->withQueryString();
    }
}
