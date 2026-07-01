<?php

namespace Modules\SPK\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'product_id' => $this->product_id,
            'quantity_reserved' => $this->quantity_reserved,
            'quantity_used' => $this->quantity_used,
            'note' => $this->note,
            'product' => new \Modules\Inventory\Http\Resources\ProductResource($this->whenLoaded('product')),
        ];
    }
}
