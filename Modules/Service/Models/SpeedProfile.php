<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SpeedProfile extends Model
{
    use BelongsToCompany;
    use HasFactory;
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
}
