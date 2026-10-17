<?php

namespace Modules\Ipam\Models;

use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ipam\Database\Factories\IpAddressFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $ip_prefix_id
 * @property int $family 4 or 6
 * @property string $address normalised text
 * @property string $address_cmp comparable string (fixed width, sortable)
 * @property string $status assigned|reserved
 * @property ?int $subscription_id
 * @property ?int $network_asset_id
 * @property ?string $note
 * @property ?int $assigned_by
 * @property ?CarbonInterface $created_at
 */
class IpAddress extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RESERVED = 'reserved';

    protected $fillable = [
        'ip_prefix_id',
        'family',
        'address',
        'address_cmp',
        'status',
        'subscription_id',
        'network_asset_id',
        'note',
        'assigned_by',
    ];

    protected $casts = [
        'family' => 'integer',
    ];

    /** @return BelongsTo<IpPrefix, $this> */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(IpPrefix::class, 'ip_prefix_id');
    }

    /** @return BelongsTo<ServiceSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    /** @return BelongsTo<NetworkAsset, $this> */
    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ip_address')
            ->logFillable()
            ->logExcept(['address_cmp'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): IpAddressFactory
    {
        return IpAddressFactory::new();
    }
}
