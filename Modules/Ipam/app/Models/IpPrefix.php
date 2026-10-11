<?php

namespace Modules\Ipam\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ipam\Database\Factories\IpPrefixFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $parent_id
 * @property int $family 4 or 6
 * @property string $cidr normalised network/length
 * @property string $range_from comparable string of the first address
 * @property string $range_to comparable string of the last address
 * @property int $prefix_length
 * @property string $usage container|customer_static|customer_dynamic|infrastructure
 * @property ?int $location_id
 * @property ?int $vlan_id
 * @property ?string $description
 */
class IpPrefix extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;

    public const USAGES = ['container', 'customer_static', 'customer_dynamic', 'infrastructure'];

    /**
     * The cidr and its derived columns are written by IpPrefixService only; they are never edited after create.
     */
    protected $fillable = [
        'parent_id',
        'family',
        'cidr',
        'range_from',
        'range_to',
        'prefix_length',
        'usage',
        'location_id',
        'vlan_id',
        'description',
    ];

    protected $casts = [
        'family' => 'integer',
        'prefix_length' => 'integer',
    ];

    /** @return BelongsTo<IpPrefix, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<IpPrefix, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Vlan, $this> */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ip_prefix')
            ->logFillable()
            ->logExcept(['range_from', 'range_to'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): IpPrefixFactory
    {
        return IpPrefixFactory::new();
    }
}
