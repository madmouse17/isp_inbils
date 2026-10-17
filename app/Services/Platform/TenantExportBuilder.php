<?php

namespace App\Services\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Services\Core\CompanyService;
use App\Support\Platform\TenantTableRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use ZipArchive;

class TenantExportBuilder
{
    private const CHUNK_SIZE = 1000;

    private const ROOT = 'platform/exports';

    /** @var array<string, list<string>> */
    private const EXTRA_OMISSIONS = [
        'network_accounts' => ['provisioned_password_hash'],
        'payment_attempts' => ['redirect_url', 'last_notification'],
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

    /** @return array{0: int, 1: list<string>} */
    private function writeTableCsv(FilesystemAdapter $disk, string $path, string $table, Builder $query): array
    {
        $columns = Schema::getColumnListing($table);
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
     * @param  list<int>  $companyIds
     * @param  array<string, string>  $entries
     * @return array<string, int>
     */
    private function mediaEntries(FilesystemAdapter $local, string $work, array $companyIds, array &$entries): array
    {
        $counts = [];

        foreach ($this->mediaDisks($companyIds) as $diskName) {
            $storage = Storage::disk($diskName);
            $counts[$diskName] = 0;

            foreach ($companyIds as $companyId) {
                foreach ($storage->allFiles("companies/{$companyId}") as $path) {
                    $path = $this->tenantMediaPath($path, $companyId);
                    $entry = "files/{$diskName}/{$path}";
                    $source = $storage->getAdapter() instanceof LocalFilesystemAdapter
                        ? $storage->path($path)
                        : $this->copyToLocal($storage, $local, $work, $diskName, $path);
                    $entries[$entry] = $source;
                    $counts[$diskName]++;
                }
            }
        }

        ksort($counts);
        ksort($entries);

        return $counts;
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

    private function tenantMediaPath(string $path, int $companyId): string
    {
        $prefix = "companies/{$companyId}/";

        if (! str_starts_with($path, $prefix) || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException("Storage returned an unsafe tenant media path [{$path}].");
        }

        return $path;
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
