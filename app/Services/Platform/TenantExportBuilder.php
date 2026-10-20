<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Platform\TenantTableRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use ZipArchive;

class TenantExportBuilder
{
    private const CHUNK_SIZE = 1000;

    private const ROOT = 'platform/exports';

    /** @var array<string, list<string>> */
    private const EXTRA_OMISSIONS = [
        'network_accounts' => ['provisioned_password_hash'],
        'payment_attempts' => ['redirect_url', 'last_notification'],
        'company_integrations' => ['credentials'],
        // A row written in a web request keeps the address and the agent of the client: on a `support` row that is the
        // platform operator's, which the platform keeps and a tenant never receives.
        'activity_log' => ['ip_address', 'user_agent'],
    ];

    public function __construct(private readonly TenantTableRegistry $registry) {}

    /** @return array{path: string, size: int, row_counts: array<string, int>} */
    public function build(TenantExport $export, Company $root): array
    {
        $companies = Company::query()
            ->whereKey($root->id)
            ->orWhere('parent_company_id', $root->id)
            ->orderBy('id')
            ->get();
        $ids = $companies->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $work = $this->workPath($export);
        $disk = Storage::disk('local');
        $disk->makeDirectory($work.'/data');
        $rowCounts = [];
        $omitted = [];

        CompanyService::runAs($root->id, function () use ($disk, $work, $ids, &$rowCounts, &$omitted): void {
            [$rowCounts['companies'], $omitted['companies']] = $this->writeTableCsv(
                $disk,
                $work.'/data/companies.csv',
                'companies',
                DB::table('companies')->whereIn('id', $ids),
            );

            foreach ($this->registry->tables() as $table) {
                if ($table === 'tenant_exports') {
                    continue;
                }

                $query = DB::table($table)->whereIn('company_id', $ids);

                if ($table === (string) config('activitylog.table_name', 'activity_log')) {
                    $query->where(fn (Builder $activity) => $activity->whereNull('log_name')->orWhere('log_name', '<>', PlatformAuditService::PLATFORM_LOG));
                }

                [$rowCounts[$table], $omitted[$table]] = $this->writeTableCsv(
                    $disk,
                    $work."/data/{$table}.csv",
                    $table,
                    $query,
                );
            }

            foreach ($this->registry->pivots() as $pivot) {
                $table = $pivot['table'];
                $query = DB::table($table);

                foreach ($pivot['parents'] as $column => $parent) {
                    $query->whereIn($column, DB::table($parent['table'])->whereIn('company_id', $ids)->select($parent['column']));
                }

                [$rowCounts[$table], $omitted[$table]] = $this->writeTableCsv(
                    $disk,
                    $work."/data/{$table}.csv",
                    $table,
                    $query,
                );
            }

            // The files are the ones these rows name (see mediaEntries); the rows say which record each file belongs to.
            [$rowCounts['media'], $omitted['media']] = $this->writeTableCsv($disk, $work.'/data/media.csv', 'media', $this->mediaOwnedBy($ids));
            // Role definitions are the platform's, not the tenant's. What the tenant owns is who holds which role.
            [$rowCounts['user_roles'], $omitted['user_roles']] = $this->writeTableCsv($disk, $work.'/data/user_roles.csv', 'user_roles', $this->userRoles($ids), ['user_id', 'role_id', 'role_name']);
        });

        $omitted = array_filter($omitted);
        $entries = [];
        $media = $this->mediaEntries($disk, $work, $ids, $entries);
        $manifest = [
            'export_uuid' => $export->uuid,
            'generated_at' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone'),
            'tenant' => ['id' => $root->id, 'code' => $root->code, 'name' => $root->name],
            'companies' => $companies->map(fn (Company $company): array => [
                'id' => $company->id,
                'code' => $company->code,
                'name' => $company->name,
                'parent_company_id' => $company->parent_company_id,
            ])->all(),
            'csv' => ['encoding' => 'UTF-8 BOM', 'delimiter' => ',', 'enclosure' => '"', 'escape' => '', 'null' => ''],
            'row_counts' => $rowCounts,
            'omitted_columns' => $omitted,
            'media' => $media,
        ];

        $archiveInWork = $work.'/archive.zip';
        $archivePath = $this->archivePath($export);
        $zip = new ZipArchive();
        $opened = $zip->open($disk->path($archiveInWork), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException("Could not open tenant export archive (zip error {$opened}).");
        }

        foreach ($disk->allFiles($work.'/data') as $path) {
            $entry = 'data/'.basename($path);

            if (! $zip->addFile($disk->path($path), $entry)) {
                throw new RuntimeException("Could not add [{$entry}] to tenant export archive.");
            }
        }

        foreach ($entries as $entry => $source) {
            if (! $zip->addFile($source, $entry)) {
                throw new RuntimeException("Could not add [{$entry}] to tenant export archive.");
            }
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (! $zip->addFromString('manifest.json', $json) || ! $zip->close()) {
            throw new RuntimeException('Could not finish tenant export archive.');
        }

        $disk->makeDirectory(self::ROOT);

        if (! $disk->move($archiveInWork, $archivePath)) {
            throw new RuntimeException('Could not move tenant export archive into place.');
        }

        $size = $disk->size($archivePath);
        $disk->deleteDirectory($work);
        $this->removeEmptyTmpDirectory($disk);

        return ['path' => $archivePath, 'size' => $size, 'row_counts' => $rowCounts];
    }

    public function discard(TenantExport $export): void
    {
        $disk = Storage::disk('local');
        $disk->delete($this->archivePath($export));
        $disk->deleteDirectory($this->workPath($export));
        $this->removeEmptyTmpDirectory($disk);
    }

    public function archivePath(TenantExport $export): string
    {
        return self::ROOT.'/'.$export->uuid.'.zip';
    }

    private function workPath(TenantExport $export): string
    {
        return self::ROOT.'/tmp/'.$export->uuid;
    }

    /**
     * @param  list<string>|null  $columns  the columns of a query that is not a plain table; the table's own by default
     * @return array{0: int, 1: list<string>}
     */
    private function writeTableCsv(FilesystemAdapter $disk, string $path, string $table, Builder $query, ?array $columns = null): array
    {
        $columns ??= Schema::getColumnListing($table);
        $omitted = array_values(array_filter($columns, fn (string $column): bool => $this->isSensitive($table, $column)));
        $kept = array_values(array_diff($columns, $omitted));
        $handle = fopen($disk->path($path), 'w+b');

        if ($handle === false || fwrite($handle, "\xEF\xBB\xBF") === false || fputcsv($handle, $kept, escape: '') === false) {
            throw new RuntimeException("Could not create [{$path}].");
        }

        $count = 0;
        $write = function (Collection $rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                $values = array_map(fn (mixed $value): mixed => $value ?? '', array_values((array) $row));

                if (fputcsv($handle, $values, escape: '') === false) {
                    throw new RuntimeException('Could not write tenant export CSV row.');
                }

                $count++;
            }
        };

        try {
            if (in_array('id', $kept, true)) {
                $query->select($kept)->orderBy('id')->chunkById(self::CHUNK_SIZE, $write, 'id');
            } else {
                $query->select($kept);

                foreach ($kept as $column) {
                    $query->orderBy($column);
                }

                $query->chunk(self::CHUNK_SIZE, $write);
            }
        } finally {
            fclose($handle);
        }

        return [$count, $omitted];
    }

    private function isSensitive(string $table, string $column): bool
    {
        if (in_array($column, self::EXTRA_OMISSIONS[$table] ?? [], true)) {
            return true;
        }

        return str_starts_with($column, 'two_factor_')
            || str_ends_with($column, 'password')
            || str_ends_with($column, 'secret')
            || str_ends_with($column, 'token');
    }

    /**
     * The files of the tenant's media rows, never a scan of its folders: a stray file in a tenant folder (a test run, a
     * reused company id, a restored backup) has no row and stays out. A row brings its original and, when it generated
     * any, its conversions. A row whose file is gone is skipped and stays in media.csv.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, string>  $entries  archive entry => file to add
     * @return array<string, int> files per disk
     */
    private function mediaEntries(FilesystemAdapter $local, string $work, array $companyIds, array &$entries): array
    {
        $counts = array_fill_keys($this->mediaDisks($companyIds), 0);

        Media::query()
            ->whereIn('id', $this->mediaOwnedBy($companyIds)->select('id'))
            ->chunkById(self::CHUNK_SIZE, function (Collection $rows) use ($local, $work, $companyIds, &$entries, &$counts): void {
                $this->loadOwners($rows);

                foreach ($rows as $media) {
                    foreach ($this->mediaPaths($media) as [$diskName, $path]) {
                        $path = $this->tenantMediaPath($path, $companyIds, $media);
                        $storage = Storage::disk($diskName);

                        if (! $storage->exists($path)) {
                            // The row outlived its file: ids only, a file name can hold a customer's name.
                            Log::warning('tenant_export.media_file_missing', ['media_id' => $media->getKey(), 'disk' => $diskName]);

                            continue;
                        }

                        $entries["files/{$diskName}/{$path}"] = $storage->getAdapter() instanceof LocalFilesystemAdapter
                            ? $storage->path($path)
                            : $this->copyToLocal($storage, $local, $work, $diskName, $path);
                        $counts[$diskName] = ($counts[$diskName] ?? 0) + 1;
                    }
                }
            });

        ksort($counts);
        ksort($entries);

        return $counts;
    }

    /**
     * Hands every media the owner the path generator reads, with one query per owner class instead of one per row, and
     * without the company scope: outside a request nothing matches that scope. An owner that cannot be loaded stays null,
     * and the generator falls back on the company id the uploader stamped on the row.
     *
     * @param  Collection<int, Media>  $rows
     */
    private function loadOwners(Collection $rows): void
    {
        foreach ($rows->groupBy('model_type') as $type => $group) {
            if (! is_string($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }

            $owners = $type::query()->withoutGlobalScopes()->whereKey($group->pluck('model_id')->unique()->all())->get()->keyBy(fn (Model $owner): mixed => $owner->getKey());

            foreach ($group as $media) {
                $media->setRelation('model', $owners->get($media->model_id));
            }
        }
    }

    /**
     * Where a row's files lie, by the same path generator that put them there.
     *
     * shortcut: responsive images are not archived. Nothing in the app generates them; add their folder here when
     * something does.
     *
     * @return list<array{0: string, 1: string}> disk and path
     */
    private function mediaPaths(Media $media): array
    {
        $generator = PathGeneratorFactory::create($media);
        $paths = [[(string) $media->disk, $generator->getPath($media).$media->file_name]];

        if (array_filter($media->generated_conversions ?? []) !== []) {
            $conversionsDisk = (string) ($media->conversions_disk ?: $media->disk);

            foreach (Storage::disk($conversionsDisk)->files(rtrim($generator->getPathForConversions($media), '/')) as $file) {
                $paths[] = [$conversionsDisk, $file];
            }
        }

        return $paths;
    }

    /** @param list<int> $companyIds @return list<string> */
    private function mediaDisks(array $companyIds): array
    {
        $rows = $this->mediaOwnedBy($companyIds)->get(['disk', 'conversions_disk']);

        return collect(['local', 'public', config('media-library.disk_name')])
            ->merge($rows->pluck('disk'))
            ->merge($rows->pluck('conversions_disk'))
            ->filter(fn (mixed $disk): bool => is_string($disk) && $disk !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param list<int> $companyIds */
    private function mediaOwnedBy(array $companyIds): Builder
    {
        $tenantTables = $this->registry->tables();
        $owners = [];

        foreach (DB::table((new Media())->getTable())->distinct()->pluck('model_type') as $type) {
            if (is_string($type) && $type !== Company::class && is_subclass_of($type, Model::class)) {
                $table = (new $type())->getTable();

                if (in_array($table, $tenantTables, true)) {
                    $owners[$type] = $table;
                }
            }
        }

        return DB::table((new Media())->getTable())->where(function (Builder $media) use ($companyIds, $owners): void {
            $media->where(fn (Builder $own) => $own->where('model_type', Company::class)->whereIn('model_id', $companyIds));

            foreach ($owners as $type => $table) {
                $media->orWhere(fn (Builder $own) => $own->where('model_type', $type)->whereIn('model_id', DB::table($table)->whereIn('company_id', $companyIds)->select('id')));
            }

            $media->orWhereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_properties, \'$.company_id\')) AS UNSIGNED)'), $companyIds);
        });
    }

    /**
     * A tenant's file lies under `companies/{its company id}/`. A row whose path leads anywhere else (another tenant's
     * folder, a way out of the root) stops the export: the archive must never carry a file that is not the tenant's.
     *
     * @param  list<int>  $companyIds
     */
    private function tenantMediaPath(string $path, array $companyIds, Media $media): string
    {
        if (preg_match('#^companies/(\d+)/#', $path, $match) !== 1
            || ! in_array((int) $match[1], $companyIds, true)
            || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException("Media {$media->getKey()} points outside the folders of the tenant [{$path}].");
        }

        return $path;
    }

    /**
     * Who holds which role, for the users the tenant owns: `users.csv` and this file are read together.
     *
     * @param  list<int>  $companyIds
     */
    private function userRoles(array $companyIds): Builder
    {
        return DB::query()->fromSub(
            DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', User::class)
                ->whereIn('model_has_roles.model_id', DB::table('users')->whereIn('company_id', $companyIds)->select('id'))
                ->select(['model_has_roles.model_id as user_id', 'model_has_roles.role_id', 'roles.name as role_name']),
            'user_roles',
        );
    }

    private function copyToLocal(FilesystemAdapter $source, FilesystemAdapter $local, string $work, string $disk, string $path): string
    {
        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$disk}:{$path}].");
        }

        $relative = $work.'/remote/'.$disk.'/'.$path;
        $absolute = $local->path($relative);

        if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0700, true) && ! is_dir(dirname($absolute))) {
            fclose($stream);
            throw new RuntimeException("Could not create temporary directory for [{$disk}:{$path}].");
        }

        $target = fopen($absolute, 'w+b');

        if ($target === false || stream_copy_to_stream($stream, $target) === false) {
            fclose($stream);
            is_resource($target) && fclose($target);
            throw new RuntimeException("Could not copy [{$disk}:{$path}] into tenant export.");
        }

        fclose($stream);
        fclose($target);

        return $absolute;
    }

    private function removeEmptyTmpDirectory(FilesystemAdapter $disk): void
    {
        $path = $disk->path(self::ROOT.'/tmp');

        if (is_dir($path)) {
            @rmdir($path);
        }
    }
}
