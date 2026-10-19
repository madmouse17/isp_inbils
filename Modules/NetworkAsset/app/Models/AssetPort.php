<?php

namespace Modules\NetworkAsset\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\NetworkAsset\Database\Factories\AssetPortFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One physical port of a router, switch, OLT uplink or ONT: a row per (group, number) of the asset's panel layout.
 * PON ports and splitter outputs are not here, they stay in odn_ports. A link is written to both ports at once
 * (AssetPanelService::link), so `linked_port_id` is never fillable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $network_asset_id
 * @property string $group_key
 * @property int $number 1-based inside the group
 * @property string $kind rj45|sfp|sc|rj11
 * @property string $name the vendor name of the port ("ether1", "sfp-sfpplus1")
 * @property ?string $label
 * @property ?string $note
 * @property bool $admin_down
 * @property ?int $linked_port_id
 */
class AssetPort extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;

    protected $fillable = [
        'network_asset_id',
        'group_key',
        'number',
        'kind',
        'name',
        'label',
        'note',
        'admin_down',
    ];

    protected $casts = [
        'number' => 'integer',
        'admin_down' => 'boolean',
    ];

    /** @return BelongsTo<NetworkAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'network_asset_id')->withTrashed();
    }

    /** @return BelongsTo<AssetPort, $this> */
    public function linkedPort(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_port_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('asset_port')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): AssetPortFactory
    {
        return AssetPortFactory::new();
    }
}
