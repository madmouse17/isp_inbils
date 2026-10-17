<?php

namespace App\Services\Platform;

use App\Jobs\Platform\BuildTenantExportJob;
use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantExportService
{
    public function __construct(
        private readonly TenantExportBuilder $builder,
        private readonly PlatformAuditService $audit,
    ) {}

    public function request(PlatformAdmin $actor, Company $root): TenantExport
    {
        $export = DB::transaction(function () use ($actor, $root): TenantExport {
            Company::query()->whereKey($root->id)->lockForUpdate()->firstOrFail();

            $active = TenantExport::query()
                ->where('company_id', $root->id)
                ->whereIn('status', [TenantExport::QUEUED, TenantExport::RUNNING])
                ->where('created_at', '>', now()->subHours(BuildTenantExportJob::STALE_AFTER_HOURS))
                ->exists();

            if ($active) {
                throw ValidationException::withMessages(['export' => 'Ekspor perusahaan ini masih diproses.']);
            }

            $export = TenantExport::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $root->id,
                'requested_by_platform_admin_id' => $actor->id,
                'status' => TenantExport::QUEUED,
            ]);

            $this->audit->platform('tenant.export_requested', [
                'export_id' => $export->id,
                'tenant_code' => $root->code,
            ], $export, $actor);
            $this->audit->support('tenant.export_requested', ['export_id' => $export->id], $export, $actor);

            return $export;
        });

        BuildTenantExportJob::dispatch($export->id, $root->id);

        return $export;
    }

    /** @param array{path: string, size: int, row_counts: array<string, int>} $built */
    public function complete(TenantExport $export, Company $root, array $built): void
    {
        $completedAt = now();
        $export->forceFill([
            'status' => TenantExport::READY,
            'file_path' => $built['path'],
            'file_size' => $built['size'],
            'row_counts' => $built['row_counts'],
            'error_message' => null,
            'completed_at' => $completedAt,
            'expires_at' => $completedAt->copy()->addDay(),
        ])->save();

        $actor = PlatformAdmin::query()->find($export->requested_by_platform_admin_id);
        $this->audit->platform('tenant.export_completed', [
            'export_id' => $export->id,
            'tenant_code' => $root->code,
            'file_size' => $export->file_size,
        ], $export, $actor);
        $this->audit->support('tenant.export_completed', ['export_id' => $export->id], $export, $actor);
    }

    public function fail(TenantExport $export, ?Throwable $error, ?PlatformAdmin $actor = null, ?string $reason = null): void
    {
        if (! in_array($export->status, [TenantExport::QUEUED, TenantExport::RUNNING], true)) {
            return;
        }

        $this->builder->discard($export);
        $class = $error === null ? 'ExportFailed' : class_basename($error::class);
        $message = $error === null ? 'Export tidak selesai.' : Str::before($error->getMessage(), "\n");
        $stored = Str::limit($class.': '.$message, 300, '');
        $export->forceFill([
            'status' => TenantExport::FAILED,
            'file_path' => null,
            'file_size' => null,
            'row_counts' => null,
            'error_message' => $stored,
            'completed_at' => null,
            'expires_at' => null,
        ])->save();

        $this->audit->platform('tenant.export_failed', array_filter([
            'export_id' => $export->id,
            'error' => $class,
            'message' => Str::limit($message, 300, ''),
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null), $export, $actor);
    }

    public function expire(TenantExport $export, string $reason, ?PlatformAdmin $actor = null): void
    {
        if ($export->status !== TenantExport::READY) {
            return;
        }

        $this->builder->discard($export);
        $export->forceFill([
            'status' => TenantExport::EXPIRED,
            'file_path' => null,
            'file_size' => null,
        ])->save();
        $this->audit->platform('tenant.export_expired', [
            'export_id' => $export->id,
            'reason' => $reason,
        ], $export, $actor);
    }

    /** @return array{expired: int, failed: int} */
    public function prune(): array
    {
        $expired = 0;
        $failed = 0;

        TenantExport::query()
            ->where('status', TenantExport::READY)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    $this->expire($export, 'window_elapsed');
                    $expired++;
                }
            });

        TenantExport::query()
            ->whereIn('status', [TenantExport::QUEUED, TenantExport::RUNNING])
            ->where('created_at', '<=', now()->subHours(BuildTenantExportJob::STALE_AFTER_HOURS))
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$failed): void {
                foreach ($exports as $export) {
                    $this->fail($export, null, reason: 'stale');
                    $failed++;
                }
            });

        return ['expired' => $expired, 'failed' => $failed];
    }

    /**
     * @param  array{company_id: ?int, status: ?string, page: int, per_page: int}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return TenantExport::query()
            ->with(['company:id,name,code', 'requestedBy:id,name'])
            ->when($filters['company_id'], fn (Builder $query, int $id) => $query->where('company_id', $id))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($filters['per_page'], page: $filters['page'])
            ->withQueryString()
            ->through(fn (TenantExport $export): array => $this->row($export));
    }

    public function isDownloadable(TenantExport $export): bool
    {
        return $export->status === TenantExport::READY
            && $export->expires_at !== null
            && $export->expires_at->isFuture();
    }

    public function fileExists(TenantExport $export): bool
    {
        return $export->file_path !== null && Storage::disk('local')->exists($export->file_path);
    }

    public function recordDownload(TenantExport $export, PlatformAdmin $actor): void
    {
        $tenantCode = $export->company()->value('code');
        $this->audit->platform('tenant.export_downloaded', [
            'export_id' => $export->id,
            'tenant_code' => $tenantCode,
        ], $export, $actor);
        $this->audit->support('tenant.export_downloaded', ['export_id' => $export->id], $export, $actor);
    }

    /** @return array<string, mixed> */
    private function row(TenantExport $export): array
    {
        return [
            'id' => $export->id,
            'uuid' => $export->uuid,
            'tenant' => [
                'id' => $export->company->id,
                'name' => $export->company->name,
                'code' => $export->company->code,
            ],
            'status' => $export->status,
            'file_size' => $export->file_size,
            'row_counts' => $export->row_counts,
            'error_message' => $export->error_message,
            'requested_by' => $export->requestedBy?->name,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $this->isDownloadable($export)
                ? URL::temporarySignedRoute('platform.exports.download', $export->expires_at, ['tenantExport' => $export->id])
                : null,
        ];
    }
}
