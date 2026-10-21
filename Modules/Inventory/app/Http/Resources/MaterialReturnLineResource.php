<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\MaterialReturnLine;

/**
 * @mixin MaterialReturnLine
 */
class MaterialReturnLineResource extends JsonResource
{
    /**
     * @var MaterialReturnLine
     */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'company_id' => $this->company?->public_id,
            'material_return_case_id' => $this->case?->public_id,
            'inventory_request_item_id' => $this->inventoryRequestItem?->public_id,
            'product_id' => $this->product?->public_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->public_id,
                'name' => $this->product->name,
                'code' => $this->product->sku,
                'sku' => $this->product->sku,
                'type' => $this->product->type,
                'unit' => $this->product->unit?->symbol ?? $this->product->unit?->name,
            ]),
            'issued_qty' => (float) $this->issued_qty,
            'actual_used_qty' => (float) $this->actual_used_qty,
            'declared_good_return_qty' => (float) $this->declared_good_return_qty,
            'declared_discrepancy_qty' => (float) $this->declared_discrepancy_qty,
            'accepted_good_return_qty' => (float) $this->accepted_good_return_qty,
            'damaged_return_qty' => (float) $this->damaged_return_qty,
            'lost_qty' => (float) $this->lost_qty,
            'other_discrepancy_qty' => (float) $this->other_discrepancy_qty,
            'discrepancy_code' => $this->discrepancy_code,
            'discrepancy_reason' => $this->discrepancy_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
