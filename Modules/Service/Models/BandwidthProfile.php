<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Service\Database\Factories\BandwidthProfileFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BandwidthProfile extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'download_mbps',
        'upload_mbps',
        'type',
        'contention_ratio',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'download_mbps' => 'integer',
        'upload_mbps' => 'integer',
        'contention_ratio' => 'integer',
    ];

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('bandwidth_profile')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): BandwidthProfileFactory
    {
        return BandwidthProfileFactory::new();
    }
}
