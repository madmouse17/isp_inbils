<?php

namespace Modules\NetworkAsset\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\NetworkAsset\Database\Factories\OdnSplitterFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A passive optical splitter (1:N) hosted by an ODC or ODP asset. Its input is an upstream port (a PON port
 * or another splitter's output); it owns N output ports.
 *
 * @property int $id
 * @property int $company_id
 * @property int $network_asset_id
 * @property int $outputs 2, 4, 8, 16, 32 or 64
 * @property ?int $input_port_id
 * @property ?string $label
 */
class OdnSplitter extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;

    public const OUTPUTS = [2, 4, 8, 16, 32, 64];

    protected $fillable = [
        'network_asset_id',
        'outputs',
        'input_port_id',
        'label',
    ];

    protected $casts = [
        'outputs' => 'integer',
    ];

    /** @return BelongsTo<NetworkAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'network_asset_id')->withTrashed();
    }

    /** @return BelongsTo<OdnPort, $this> */
    public function inputPort(): BelongsTo
    {
        return $this->belongsTo(OdnPort::class, 'input_port_id');
    }

    /** @return HasMany<OdnPort, $this> */
    public function ports(): HasMany
    {
        return $this->hasMany(OdnPort::class, 'odn_splitter_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('odn_splitter')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): OdnSplitterFactory
    {
        return OdnSplitterFactory::new();
    }
}
