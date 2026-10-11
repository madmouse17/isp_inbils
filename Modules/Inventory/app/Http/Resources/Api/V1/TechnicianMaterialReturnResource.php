<?php

namespace Modules\Inventory\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;

/**
 * A custody case as its technician sees it: lines and own declaration, never the warehouse actors or the
 * idempotency and payload hashes.
 *
 * @property MaterialReturnCase $resource
 */
class TechnicianMaterialReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $case = $this->resource;

        return [
            'id' => $case->id,
            'status' => $case->status,
            'inventory_request' => $case->relationLoaded('inventoryRequest') ? [
                'id' => $case->inventoryRequest->id,
                'code' => $case->inventoryRequest->code,
                'status' => $case->inventoryRequest->status,
            ] : null,
            'work_order' => $case->relationLoaded('workOrder') && $case->workOrder !== null ? [
                'id' => $case->workOrder->id,
                'code' => $case->workOrder->code,
            ] : null,
            'warehouse_location' => $case->relationLoaded('warehouseLocation') && $case->warehouseLocation !== null ? [
                'id' => $case->warehouseLocation->id,
                'name' => $case->warehouseLocation->name,
                'code' => $case->warehouseLocation->code,
            ] : null,
            'declaration_version' => $case->declaration_version,
            'declared_at' => $case->declared_at,
            'rejection_reason' => $case->rejection_reason,
            'resolution_note' => $case->resolution_note,
            'lines' => $case->lines->map(fn (MaterialReturnLine $line): array => [
                'id' => $line->id,
                'product' => [
                    'id' => $line->product_id,
                    'name' => $line->product->name,
                    'sku' => $line->product->sku,
                    'unit' => data_get($line, 'product.unit.symbol') ?? data_get($line, 'product.unit.name'),
                ],
                'issued_qty' => (float) $line->issued_qty,
                'actual_used_qty' => (float) $line->actual_used_qty,
                'declared_good_return_qty' => (float) $line->declared_good_return_qty,
                'declared_discrepancy_qty' => (float) $line->declared_discrepancy_qty,
                'discrepancy_code' => $line->discrepancy_code,
                'discrepancy_reason' => $line->discrepancy_reason,
            ])->values()->all(),
            'can' => [
                'declare' => $request->user()?->can('declare', $case) ?? false,
            ],
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
        ];
    }
}
