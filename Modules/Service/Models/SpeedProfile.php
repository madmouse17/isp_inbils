<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Service\Database\Factories\SpeedProfileFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SpeedProfile extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'download_max_mbps',
        'upload_max_mbps',
        'burst_download_mbps',
        'burst_upload_mbps',
        'radius_profile_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'download_max_mbps' => 'integer',
        'upload_max_mbps' => 'integer',
        'burst_download_mbps' => 'integer',
        'burst_upload_mbps' => 'integer',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Profil kecepatan';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** A package and every version of it name the profile; the versions keep it from a delete after the package moved on. */
    public static function lifecycleReferences(): ?array
    {
        return [
            'paket' => ['service_packages.speed_profile_id'],
            'versi paket' => ['service_package_versions.speed_profile_id'],
        ];
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('speed_profile')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): SpeedProfileFactory
    {
        return SpeedProfileFactory::new();
    }
}
