<?php

namespace Modules\Ipam\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ipam\Database\Factories\VlanFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $location_id
 * @property int $vid
 * @property string $name
 * @property ?string $description
 */
class Vlan extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'location_id',
        'vid',
        'name',
        'description',
    ];

    protected $casts = [
        'vid' => 'integer',
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<IpPrefix, $this> */
    public function prefixes(): HasMany
    {
        return $this->hasMany(IpPrefix::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('vlan')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): VlanFactory
    {
        return VlanFactory::new();
    }
}
