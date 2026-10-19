<?php

namespace Modules\NetworkAsset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the catalogue of device faces (database/data/port_templates.json): shared reference data, so there is no
 * company scope. An asset copies `layout` into its own port_layout when the model is applied and never reads this
 * table again to draw itself.
 *
 * @property int $id
 * @property string $key
 * @property string $vendor
 * @property string $model
 * @property string $asset_type
 * @property ?int $height_u
 * @property array<string, mixed> $layout
 * @property ?string $source_url
 */
class PortTemplate extends Model
{
    /** The asset types that have catalogue rows. */
    public const ASSET_TYPES = ['router', 'switch', 'olt', 'onu_ont', 'odc', 'odp'];

    protected $fillable = [
        'key',
        'vendor',
        'model',
        'asset_type',
        'height_u',
        'layout',
        'source_url',
    ];

    protected $casts = [
        'height_u' => 'integer',
        'layout' => 'array',
    ];
}
