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
 * The RADIUS user (`local@realm`) of one subscription.
 *
 * Deliberately without LogsActivity (see NasDevice). The provisioning and status columns are written by
 * the engine with forceFill, so they are not fillable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $subscription_id
 * @property string $service_type pppoe|hotspot
 * @property string $username
 * @property string $password
 * @property ?string $provisioned_username
 * @property ?string $provisioned_group the RADIUS group last written; null = absent from RADIUS
 * @property ?string $provisioned_password_hash HMAC of the password last written to RADIUS
 * @property ?CarbonInterface $provisioned_at
 * @property ?CarbonInterface $pending_disconnect_at a Disconnect is owed since then; set before RADIUS is touched, cleared with the record
 * @property string $sync_status pending|synced|failed
 * @property ?string $last_error
 * @property ?CarbonInterface $last_attempt_at
 */
class NetworkAccount extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'subscription_id',
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
    ];

    /**
     * Trashed too: a deleted subscription must still resolve, so its RADIUS user is removed.
     *
     * @return BelongsTo<ServiceSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class)->withTrashed();
    }

    protected static function newFactory(): NetworkAccountFactory
    {
        return NetworkAccountFactory::new();
    }
}
