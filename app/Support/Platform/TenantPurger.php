<?php

namespace App\Support\Platform;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Deletes a tenant (a top-level company with its subsidiaries) and everything it owns, for good. Only the artisan
 * command `inbils:platform:purge-company` calls it; there is no web route.
 *
 * What a purge reaches comes from TenantTableRegistry (every table with a company_id, children first, plus the pivot
 * tables that link tenant rows) and from the tables that reach a tenant through a morph or a user: notifications,
 * media, tokens, sessions, reset tokens and roles. The database work is one transaction: either the tenant is gone or
 * nothing changed. Files are deleted after the commit, because a file cannot be rolled back; the ones that could
 * not be deleted come back as paths the operator must remove by hand.
 *
 * The platform audit rows of the tenant survive: their company link is cut and the tenant's code and name are copied
 * into their properties first. Every other activity row of the tenant is deleted with it.
 */
class TenantPurger
{
    public const MIN_SUSPENDED_DAYS = 30;

    public function __construct(
        private readonly TenantTableRegistry $registry,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * Why this tenant may not be purged. An empty list means it may.
     *
     * @return list<string>
     */
    public function blockers(Company $company): array
    {
        $ids = $this->companyIds($company);
        $blockers = [];

        if ($company->parent_company_id !== null) {
            $blockers[] = 'It is a subsidiary. Purge its tenant (the Head company) instead.';
        }

        if (Company::query()->whereIn('id', $ids)->where('is_active', true)->exists()) {
            $blockers[] = 'It, or one of its subsidiaries, is still active. Suspend the tenant first.';
        }

        if ($company->suspended_at === null || $company->suspended_at->gt(now()->subDays(self::MIN_SUSPENDED_DAYS))) {
            $blockers[] = 'It has not been suspended for '.self::MIN_SUSPENDED_DAYS.' days'.($company->suspended_at === null ? ' (no suspension is recorded).' : ' (suspended '.$company->suspended_at->toDateTimeString().').');
        }

        if ($company->deletion_requested_at === null) {
            $blockers[] = 'Nobody requested its deletion.';
        }

        if ($company->suspended_at !== null && ! TenantExport::query()->where('company_id', $company->id)->completedSince(Carbon::instance($company->suspended_at))->exists()) {
            $blockers[] = 'No export of its data was completed after the suspension.';
        }

        if (($strangers = $this->usersWithMembershipsOutside($ids)) !== []) {
            $blockers[] = 'Users homed here are also members of another company (user ids '.implode(', ', array_slice($strangers, 0, 20)).'). Revoke those memberships first.';
        }

        return $blockers;
    }

    /**
     * What a purge would delete: rows per tenant table (only tables with rows), per pivot table, media and export rows.
     * The platform audit rows of the tenant are not counted: they stay.
     *
     * @return array{company_ids: list<int>, users: int, tables: array<string, int>, pivots: array<string, int>, media: int, exports: int, rows: int}
     */
    public function plan(Company $company): array
    {
        $ids = $this->companyIds($company);
        $tables = [];
        $pivots = [];

        foreach ($this->registry->tables() as $table) {
            if (($count = $this->owned($table, $ids)->count()) > 0) {
                $tables[$table] = $count;
            }
        }

        foreach ($this->registry->pivots() as $pivot) {
            if (($count = $this->pivotRows($pivot, $ids)->count()) > 0) {
                $pivots[$pivot['table']] = $count;
            }
        }

        $media = $this->mediaOwnedBy($ids)->count();

        return [
            'company_ids' => $ids,
            'users' => $tables['users'] ?? 0,
            'tables' => $tables,
            'pivots' => $pivots,
            'media' => $media,
            'exports' => $tables['tenant_exports'] ?? 0,
            'rows' => array_sum($tables) + array_sum($pivots) + $media + count($ids),
        ];
    }

    /**
     * Deletes the tenant. The caller has shown the plan and got the typed confirmation. The database is left unchanged
     * when this throws.
     *
     * @return list<string> files and directories that could not be deleted (`disk:path`); empty when all are gone
     *
     * @throws Throwable
     */
    public function purge(Company $company): array
    {
        $ids = $this->companyIds($company);
        $companies = Company::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name']);
        $plan = $this->plan($company);
        $disks = $this->mediaDisks($ids);
        $archives = TenantExport::query()->whereIn('company_id', $ids)->get(['uuid', 'file_path'])->all();
        $identity = ['tenant_code' => $company->code, 'tenant_name' => $company->name, 'company_ids' => $ids];

        $this->audit->platform('tenant.purge_started', [
            ...$identity,
            'users' => $plan['users'],
            'counts' => $plan['tables'],
            'pivot_counts' => $plan['pivots'],
            'media' => $plan['media'],
        ], $company);

        try {
            DB::transaction(function () use ($company, $ids, $companies): void {
                $locked = Company::query()->whereKey($company->id)->lockForUpdate()->first();
                $blockers = $locked === null ? ['It no longer exists.'] : $this->blockers($locked);

                if ($blockers !== []) {
                    throw new RuntimeException('The tenant may not be purged any more: '.implode(' ', $blockers));
                }

                $this->keepPlatformAudit($companies);
                $this->deleteDependants($ids);
                $this->detach($ids);

                foreach ($this->registry->tables() as $table) {
                    $this->owned($table, $ids)->delete();
                }

                DB::table('companies')->whereIn('id', array_slice($ids, 1))->delete();
                DB::table('companies')->where('id', $ids[0])->delete();
            });
        } catch (Throwable $e) {
            $this->audit->platform('tenant.purge_failed', [
                ...$identity,
                'error' => class_basename($e::class),
                'message' => Str::limit($e->getMessage(), 300, ''),
            ], $company);

            throw $e;
        }

        $left = $this->deleteFiles($ids, $disks, $archives);

        $this->audit->platform('tenant.purge_completed', [...$identity, 'rows' => $plan['rows'], 'files_left' => $left]);

        return $left;
    }

    /** @return list<int> the tenant's company ids, the Head first */
    private function companyIds(Company $company): array
    {
        return [
            (int) $company->id,
            ...Company::query()->where('parent_company_id', $company->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * The rows of a tenant table that belong to the tenant. The platform audit of a tenant is not its data: it is
     * never counted or deleted here.
     *
     * @param  list<int>  $ids
     */
    private function owned(string $table, array $ids): Builder
    {
        $query = DB::table($table)->whereIn('company_id', $ids);

        if ($table === $this->activityTable()) {
            $query->where(fn (Builder $log) => $log->whereNull('log_name')->orWhere('log_name', '<>', PlatformAuditService::PLATFORM_LOG));
        }

        return $query;
    }

    private function activityTable(): string
    {
        $table = (string) config('activitylog.table_name', 'activity_log');

        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException("activitylog.table_name [{$table}] is not a plain lowercase identifier.");
        }

        return $table;
    }

    /**
     * @param  array{table: string, parents: array<string, array{table: string, column: string}>}  $pivot
     * @param  list<int>  $ids
     */
    private function pivotRows(array $pivot, array $ids): Builder
    {
        return DB::table($pivot['table'])->where(function (Builder $rows) use ($pivot, $ids): void {
            foreach ($pivot['parents'] as $column => $parent) {
                $rows->orWhereIn($column, DB::table($parent['table'])->whereIn('company_id', $ids)->select($parent['column']));
            }
        });
    }

    /**
     * Users homed in the tenant that are also members of a company outside it: deleting them would take another
     * tenant's member away.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function usersWithMembershipsOutside(array $ids): array
    {
        return DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->whereIn('users.company_id', $ids)
            ->whereNotIn('company_user.company_id', $ids)
            ->distinct()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The platform audit rows of each company keep their place in the log without the company: the link is cut and the
     * tenant's code and name move into the row, so "tenant X was suspended" still reads after X is gone.
     *
     * @param  iterable<Company>  $companies
     */
    private function keepPlatformAudit(iterable $companies): void
    {
        $log = $this->activityTable();

        foreach ($companies as $company) {
            DB::update(
                "UPDATE `{$log}` SET company_id = NULL, properties = JSON_SET(COALESCE(properties, JSON_OBJECT()), '$.tenant_code', ?, '$.tenant_name', ?) WHERE log_name = ? AND company_id = ?",
                [$company->code, $company->name, PlatformAuditService::PLATFORM_LOG, $company->id],
            );
        }
    }

    /**
     * Rows that reach the tenant through a pivot, a morph or a user, not through a company_id. They are deleted
     * before the rows they are found through.
     *
     * @param  list<int>  $ids
     */
    private function deleteDependants(array $ids): void
    {
        foreach ($this->registry->pivots() as $pivot) {
            $this->pivotRows($pivot, $ids)->delete();
        }

        $this->mediaOwnedBy($ids)->delete();

        $users = DB::table('users')->whereIn('company_id', $ids)->select('id');
        $customers = DB::table('customers')->whereIn('company_id', $ids)->select('id');

        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
        DB::table('notifications')->where('notifiable_type', Customer::class)->whereIn('notifiable_id', $customers)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $users)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $users)->delete();
        DB::table('sessions')->whereIn('user_id', $users)->delete();
        DB::table((string) config('auth.passwords.users.table'))->whereIn('email', DB::table('users')->whereIn('company_id', $ids)->select('email'))->delete();
    }

    /**
     * Sets the columns the order of deletion cannot respect (a tree's parent, the splitter's upstream port) to NULL.
     *
     * @param  list<int>  $ids
     */
    private function detach(array $ids): void
    {
        foreach ($this->registry->detachedColumns() as $detached) {
            DB::table($detached['table'])->whereIn('company_id', $ids)->update([$detached['column'] => null]);
        }
    }

    /**
     * The media rows that belong to the tenant: attached to one of its companies or to a row of a tenant table, or
     * stamped with its company id by the uploader. The owner's table comes from the model class stored with the row,
     * and only counts when that class is an Eloquent model whose table the registry knows.
     *
     * @param  list<int>  $ids
     */
    private function mediaOwnedBy(array $ids): Builder
    {
        $tenantTables = $this->registry->tables();
        $owners = [];

        foreach (DB::table('media')->distinct()->pluck('model_type') as $type) {
            if (is_string($type) && $type !== Company::class && is_subclass_of($type, Model::class)) {
                $table = (new $type())->getTable();

                if (in_array($table, $tenantTables, true)) {
                    $owners[$type] = $table;
                }
            }
        }

        return DB::table('media')->where(function (Builder $media) use ($ids, $owners): void {
            $media->where(fn (Builder $own) => $own->where('model_type', Company::class)->whereIn('model_id', $ids));

            foreach ($owners as $type => $table) {
                $media->orWhere(fn (Builder $own) => $own->where('model_type', $type)->whereIn('model_id', DB::table($table)->whereIn('company_id', $ids)->select('id')));
            }

            $media->orWhereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_properties, \'$.company_id\')) AS UNSIGNED)'), $ids);
        });
    }

    /**
     * The disks a tenant's files may sit on: the private and the public one, the configured media disk, and every disk
     * a media row of the tenant names.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function mediaDisks(array $ids): array
    {
        $named = $this->mediaOwnedBy($ids)->get(['disk', 'conversions_disk']);

        return collect(['local', 'public', config('media-library.disk_name')])
            ->merge($named->pluck('disk'))
            ->merge($named->pluck('conversions_disk'))
            ->filter(fn ($disk): bool => is_string($disk) && $disk !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Deletes `companies/{id}` on every disk and the export archives, after the commit.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $disks
     * @param  list<TenantExport>  $archives
     * @return list<string> `disk:path` of whatever is still there
     */
    private function deleteFiles(array $ids, array $disks, array $archives): array
    {
        $left = [];

        foreach ($disks as $disk) {
            foreach ($ids as $id) {
                $this->remove($disk, "companies/{$id}", $left);
            }
        }

        foreach ($archives as $archive) {
            if ($archive->file_path !== null) {
                $this->remove('local', $archive->file_path, $left);
            }

            $this->remove('local', "platform/exports/tmp/{$archive->uuid}", $left);
        }

        return $left;
    }

    /**
     * Deletes a file or a directory; adds `disk:path` to $left when it is still there afterwards or the disk is unusable.
     *
     * @param  list<string>  $left
     */
    private function remove(string $disk, string $path, array &$left): void
    {
        try {
            if (! is_array(config("filesystems.disks.{$disk}")) || str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new RuntimeException("Unusable disk or path [{$disk}:{$path}].");
            }

            $storage = Storage::disk($disk);
            $storage->delete($path);
            $storage->deleteDirectory($path);

            if ($storage->exists($path)) {
                $left[] = "{$disk}:{$path}";
            }
        } catch (Throwable) {
            $left[] = "{$disk}:{$path}";
        }
    }
}
