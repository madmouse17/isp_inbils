<?php

namespace Modules\NetworkAsset\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\NetworkAsset\Database\Factories\NasDeviceFactory;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * A NAS router registered as a FreeRADIUS client, one per `router` network asset.
 *
 * Deliberately without LogsActivity: the encrypted shared secret would reach the activity log decrypted.
 * Audit is explicit (AuditService) and never carries a secret. The RADIUS bookkeeping columns are written by
 * the engine with forceFill, so they are not fillable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $network_asset_id
 * @property ?string $nasname IPv4 address FreeRADIUS sees as the source of the NAS
 * @property int $coa_port
 * @property ?string $radius_secret
 * @property ?string $radius_nasname the nasname last written to RADIUS
 * @property ?CarbonInterface $radius_synced_at
 * @property ?string $last_error
 * @property-read ?string $realm
 */
class NasDevice extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'network_asset_id',
        'nasname',
        'coa_port',
        'radius_secret',
    ];

    protected $attributes = ['coa_port' => 1700];

    protected $hidden = ['radius_secret'];

    protected $casts = [
        'radius_secret' => 'encrypted',
        'coa_port' => 'integer',
        'radius_synced_at' => 'datetime',
    ];

    /** The company realm, or null while the company code is not a valid one. */
    protected function realm(): Attribute
    {
        return Attribute::get(fn (): ?string => Realm::forCompany((int) $this->company_id));
    }

    /** @return BelongsTo<NetworkAsset, $this> */
    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    protected static function newFactory(): NasDeviceFactory
    {
        return NasDeviceFactory::new();
    }
}
