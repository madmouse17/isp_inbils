<?php

namespace Modules\NetworkAsset\Models;

use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\NetworkAsset\Database\Factories\NetworkAccountFactory;

/**
 * The PPPoE secret or hotspot user of one subscription.
 *
 * Deliberately without LogsActivity (see NasDevice). The provisioning and status columns are written by
 * the engine with forceFill, so they are not fillable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $subscription_id
 * @property int $nas_device_id
 * @property string $service_type pppoe|hotspot
 * @property string $username
 * @property string $password
 * @property ?int $provisioned_nas_device_id
 * @property ?string $provisioned_username
 * @property ?string $provisioned_profile null = absent on the router
 * @property ?string $provisioned_service_type pppoe|hotspot, where the secret lives on the router
 * @property ?string $provisioned_password_hash HMAC of the password last pushed to the router
 * @property ?CarbonInterface $provisioned_at
 * @property ?CarbonInterface $pending_disconnect_at a session drop is owed since then; set before the router write that needs it, cleared with the record
 * @property string $sync_status pending|synced|failed
 * @property ?string $last_error
 * @property ?CarbonInterface $last_attempt_at
 * @property ?CarbonInterface $last_online_at
 * @property ?string $current_address
 */
class NetworkAccount extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'nas_device_id',
        'service_type',
        'username',
        'password',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'provisioned_at' => 'datetime',
        'pending_disconnect_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_online_at' => 'datetime',
    ];

    /**
     * Trashed too: a deleted subscription must still resolve, so its secret is removed from the router.
     *
     * @return BelongsTo<ServiceSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class)->withTrashed();
    }

    /** @return BelongsTo<NasDevice, $this> */
    public function nasDevice(): BelongsTo
    {
        return $this->belongsTo(NasDevice::class);
    }

    /** @return BelongsTo<NasDevice, $this> */
    public function provisionedNasDevice(): BelongsTo
    {
        return $this->belongsTo(NasDevice::class, 'provisioned_nas_device_id');
    }

    protected static function newFactory(): NetworkAccountFactory
    {
        return NetworkAccountFactory::new();
    }
}
