<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\OptimisticLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category?->public_id,
            'unit_id' => $this->unit?->public_id,
            'type' => $this->type,
            'track_stock' => $this->track_stock,
            'sell_price' => $this->sell_price,
            'cost_price' => $this->cost_price,
            'min_stock' => $this->min_stock,
            'is_active' => $this->is_active,
            ...$this->lifecycleAttributes(),
            'updated_at' => OptimisticLock::version($this->resource),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'stocks' => StockResource::collection($this->whenLoaded('stocks')),
            'movements' => StockMovementResource::collection($this->whenLoaded('movements')),
        ];
    }
}
