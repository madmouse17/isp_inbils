<?php

namespace App\Models\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use Database\Factories\TenantExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One export of a tenant's data (root company plus subsidiaries), requested from the platform panel and built by a
 * job on the `platform` queue. Platform metadata, not tenant data: it does not use BelongsToCompany and is read only
 * behind auth:platform or inside platform jobs and commands.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $requested_by_platform_admin_id
 * @property string $status
 * @property string|null $file_path
 * @property int|null $file_size
 * @property array<string, int>|null $row_counts
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class TenantExport extends Model
{
    /** @use HasFactory<TenantExportFactory> */
    use HasFactory;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const STATUSES = [self::QUEUED, self::RUNNING, self::READY, self::FAILED, self::EXPIRED];

    protected static string $factory = TenantExportFactory::class;

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'company_id',
        'requested_by_platform_admin_id',
        'status',
        'file_path',
        'file_size',
        'row_counts',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'row_counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by_platform_admin_id');
    }

    /**
     * Exports that finished at or after $moment and delivered an archive. An `expired` row counts: cleanup deletes the
     * file, not the proof that the data was handed over before the tenant was deleted.
     *
     * @param  Builder<TenantExport>  $query
     * @return Builder<TenantExport>
     */
    public function scopeCompletedSince(Builder $query, Carbon $moment): Builder
    {
        return $query->whereIn('status', [self::READY, self::EXPIRED])->where('completed_at', '>=', $moment);
    }
}
