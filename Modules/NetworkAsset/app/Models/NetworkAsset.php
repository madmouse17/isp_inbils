<?php

namespace Modules\NetworkAsset\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class NetworkAsset extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'code',
        'name',
        'asset_type',
        'serial_number',
        'mac_address',
        'ip_address',
        'management_ip',
        'location_id',
        'customer_id',
        'subscription_id',
        'status',
        'ownership',
        'vendor',
        'model',
        'purchase_date',
        'purchase_price',
        'warranty_expiry',
        'notes',
        'installed_at',
        'retired_at',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'warranty_expiry' => 'date',
        'installed_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inventory\Models\Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(NetworkAssetInstallation::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('network_asset')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
