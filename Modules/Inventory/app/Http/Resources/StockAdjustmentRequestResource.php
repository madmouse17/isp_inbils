<?php

namespace Modules\Inventory\Http\Resources;

use App\Http\Resources\LocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockAdjustmentRequest;

/** @mixin StockAdjustmentRequest */
class StockAdjustmentRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'old_quantity' => $this->old_quantity,
            'new_quantity' => $this->new_quantity,
            'delta' => $this->delta,
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'product' => new ProductResource($this->whenLoaded('product')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'can' => [
                'approve' => $user?->can('approve', $this->resource) ?? false,
                'reject' => $user?->can('reject', $this->resource) ?? false,
            ],
        ];
    }
}
