<?php

namespace Modules\NetworkAsset\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NetworkAssetInstallation extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'network_asset_installations';

    protected $fillable = [
        'network_asset_id',
        'location_id',
        'customer_id',
        'subscription_id',
        'spk_id',
        'installed_by',
        'installed_at',
        'removed_at',
        'removal_reason',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'network_asset_id');
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

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }
}
