<?php

namespace Modules\Inventory\Http\Resources\Api\V1;

use App\Models\Core\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place stock can be requested from, for the technician app's picker.
 *
 * @property Location $resource
 */
class TechnicianWarehouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'code' => $this->resource->code,
        ];
    }
}
