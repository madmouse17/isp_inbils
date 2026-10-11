<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnEvent;

/**
 * @mixin MaterialReturnCase
 */
class MaterialReturnCaseResource extends JsonResource
{
    /**
     * @var MaterialReturnCase
     */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'inventory_request_id' => $this->inventory_request_id,
            'inventory_request' => $this->whenLoaded('inventoryRequest', fn () => [
                'id' => $this->inventoryRequest->id,
                'code' => $this->inventoryRequest->code,
                'status' => $this->inventoryRequest->status,
            ]),
            'work_order_id' => $this->work_order_id,
            'work_order' => $this->whenLoaded('workOrder', fn () => [
                'id' => $this->workOrder?->id,
                'code' => $this->workOrder?->code,
                'status' => $this->workOrder?->status,
            ]),
            'warehouse_location_id' => $this->warehouse_location_id,
            'warehouse_location' => $this->whenLoaded('warehouseLocation', fn () => [
                'id' => $this->warehouseLocation?->id,
                'name' => $this->warehouseLocation?->name,
                'code' => $this->warehouseLocation?->code,
            ]),
            'technician_id' => $this->technician_id,
            'technician' => $this->whenLoaded('technician', fn () => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                'email' => $this->technician->email,
            ]),
            'status' => $this->status,
            'declaration_version' => $this->declaration_version,
            'declared_by' => $this->declared_by,
            'declarer' => $this->whenLoaded('declarer', fn () => [
                'id' => $this->declarer?->id,
                'name' => $this->declarer?->name,
            ]),
            'declared_at' => $this->declared_at?->toISOString(),
            'received_by' => $this->received_by,
            'receiver' => $this->whenLoaded('receiver', fn () => [
                'id' => $this->receiver?->id,
                'name' => $this->receiver?->name,
            ]),
            'received_at' => $this->received_at?->toISOString(),
            'reconciled_by' => $this->reconciled_by,
            'reconciler' => $this->whenLoaded('reconciler', fn () => [
                'id' => $this->reconciler?->id,
                'name' => $this->reconciler?->name,
            ]),
            'reconciled_at' => $this->reconciled_at?->toISOString(),
            'rejected_by' => $this->rejected_by,
            'rejecter' => $this->whenLoaded('rejecter', fn () => [
                'id' => $this->rejecter?->id,
                'name' => $this->rejecter?->name,
            ]),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'resolved_by' => $this->resolved_by,
            'resolver' => $this->whenLoaded('resolver', fn () => [
                'id' => $this->resolver?->id,
                'name' => $this->resolver?->name,
            ]),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolution_note' => $this->resolution_note,
            'idempotency_key' => $this->idempotency_key,
            'lines' => MaterialReturnLineResource::collection($this->whenLoaded('lines')),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(function ($event): array {
                /** @var MaterialReturnEvent $event */
                return [
                    'id' => $event->id,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'action' => $event->action,
                    'actor_id' => $event->actor_id,
                    'actor_name' => $event->actor->name ?? null,
                    'reason' => $event->reason,
                    'payload' => $event->payload,
                    'created_at' => $event->created_at?->toISOString(),
                ];
            })->all()),
            'can' => [
                'declare' => $user ? $user->can('declare', $this->resource) : false,
                'receive' => $user ? $user->can('receive', $this->resource) : false,
                'resolve' => $user ? $user->can('resolve', $this->resource) : false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
