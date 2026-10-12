<?php

namespace Modules\NetworkAsset\Models;

use App\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\NetworkAsset\Database\Factories\NasDeviceFactory;

/**
 * A MikroTik router registered for provisioning, one per `router` network asset.
 *
 * Deliberately without LogsActivity: the encrypted password would reach the activity log decrypted.
 * Audit is explicit (AuditService) and never carries a secret. Health and provisioning columns are
 * written by the engine with forceFill, so they are not fillable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $network_asset_id
 * @property string $api_protocol rest|binary
 * @property int $api_port
 * @property string $api_username
 * @property string $api_password
 * @property string $isolir_profile
 * @property bool $provisioning_enabled
 * @property ?string $routeros_version
 * @property ?CarbonInterface $last_checked_at
 * @property ?CarbonInterface $last_seen_at
 * @property ?CarbonInterface $unreachable_since
 * @property ?string $last_error
 * @property int $unmanaged_count
 * @property ?list<string> $unmanaged_usernames
 */
class NasDevice extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /** rest = RouterOS 7.1+ REST over HTTPS; binary = the API service of RouterOS 6 and 7. */
    public const API_PROTOCOLS = ['rest', 'binary'];

    protected $fillable = [
        'network_asset_id',
        'api_protocol',
        'api_port',
        'api_username',
        'api_password',
        'isolir_profile',
        'provisioning_enabled',
    ];

    protected $attributes = ['api_protocol' => 'rest'];

    protected $hidden = ['api_password'];

    protected $casts = [
        'api_password' => 'encrypted',
        'api_port' => 'integer',
        'provisioning_enabled' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'unreachable_since' => 'datetime',
        'unmanaged_count' => 'integer',
        'unmanaged_usernames' => 'array',
    ];

    /** @return BelongsTo<NetworkAsset, $this> */
    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    /** @return HasMany<NetworkAccount, $this> */
    public function networkAccounts(): HasMany
    {
        return $this->hasMany(NetworkAccount::class);
    }

    protected static function newFactory(): NasDeviceFactory
    {
        return NasDeviceFactory::new();
    }
}
