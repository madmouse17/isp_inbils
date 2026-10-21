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
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'code' => $this->resource->code,
            /** True for the warehouse of the caller's latest request: the picker of a new request starts there. */
            'last_used' => (bool) $this->resource->getAttribute('last_used'),
            /** How many products are free to ask for here (stock minus reserved above zero), as the picker lists them. The picker starts at the warehouse that holds most. */
            'available_products' => (int) $this->resource->getAttribute('available_products'),
        ];
    }
}
