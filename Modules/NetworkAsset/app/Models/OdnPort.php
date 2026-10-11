<?php

namespace Modules\NetworkAsset\Models;

use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\NetworkAsset\Database\Factories\OdnPortFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A fibre port: a PON port of an OLT, or one output of a splitter in an ODC or ODP. A port is occupied when
 * a subscription holds it (a customer drop) or it is the input of a splitter. `pon_number` is a stored
 * generated column that only exists to make "one PON number per OLT" a unique index.
 *
 * @property int $id
 * @property int $company_id
 * @property int $network_asset_id the OLT for a pon port; the host ODC/ODP for a splitter output
 * @property ?int $odn_splitter_id
 * @property string $kind pon|splitter_out
 * @property int $number
 * @property ?string $label
 * @property ?int $subscription_id
 */
class OdnPort extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;

    public const KIND_PON = 'pon';

    public const KIND_SPLITTER_OUT = 'splitter_out';

    protected $fillable = [
        'network_asset_id',
        'odn_splitter_id',
        'kind',
        'number',
        'label',
        'subscription_id',
    ];

    protected $casts = [
        'number' => 'integer',
    ];

    /** @return BelongsTo<NetworkAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'network_asset_id');
    }

    /** @return BelongsTo<OdnSplitter, $this> */
    public function splitter(): BelongsTo
    {
        return $this->belongsTo(OdnSplitter::class, 'odn_splitter_id');
    }

    /** @return BelongsTo<ServiceSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    /**
     * The splitter this port feeds, if any.
     *
     * @return HasOne<OdnSplitter, $this>
     */
    public function feedsSplitter(): HasOne
    {
        return $this->hasOne(OdnSplitter::class, 'input_port_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('odn_port')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): OdnPortFactory
    {
        return OdnPortFactory::new();
    }
}
