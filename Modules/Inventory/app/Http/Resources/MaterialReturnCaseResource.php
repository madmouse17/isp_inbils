<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\AuditProperties;
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
            'id' => $this->public_id,
            'company_id' => $this->company?->public_id,
            'inventory_request_id' => $this->inventoryRequest?->public_id,
            'inventory_request' => $this->whenLoaded('inventoryRequest', fn () => [
                'id' => $this->inventoryRequest->public_id,
                'code' => $this->inventoryRequest->code,
                'status' => $this->inventoryRequest->status,
            ]),
            'work_order_id' => $this->workOrder?->public_id,
            'work_order' => $this->whenLoaded('workOrder', fn () => [
                'id' => $this->workOrder?->public_id,
                'code' => $this->workOrder?->code,
                'status' => $this->workOrder?->status,
            ]),
            'warehouse_location_id' => $this->warehouseLocation?->public_id,
            'warehouse_location' => $this->whenLoaded('warehouseLocation', fn () => [
                'id' => $this->warehouseLocation?->public_id,
                'name' => $this->warehouseLocation?->name,
                'code' => $this->warehouseLocation?->code,
            ]),
            'technician_id' => $this->technician?->public_id,
            'technician' => $this->whenLoaded('technician', fn () => [
                'id' => $this->technician->public_id,
                'name' => $this->technician->name,
                'email' => $this->technician->email,
            ]),
            'status' => $this->status,
            'declaration_version' => $this->declaration_version,
            'declared_by' => $this->declarer?->public_id,
            'declarer' => $this->whenLoaded('declarer', fn () => [
                'id' => $this->declarer?->public_id,
                'name' => $this->declarer?->name,
            ]),
            'declared_at' => $this->declared_at?->toISOString(),
            'received_by' => $this->receiver?->public_id,
            'receiver' => $this->whenLoaded('receiver', fn () => [
                'id' => $this->receiver?->public_id,
                'name' => $this->receiver?->name,
            ]),
            'received_at' => $this->received_at?->toISOString(),
            'reconciled_by' => $this->reconciler?->public_id,
            'reconciler' => $this->whenLoaded('reconciler', fn () => [
                'id' => $this->reconciler?->public_id,
                'name' => $this->reconciler?->name,
            ]),
            'reconciled_at' => $this->reconciled_at?->toISOString(),
            'rejected_by' => $this->rejecter?->public_id,
            'rejecter' => $this->whenLoaded('rejecter', fn () => [
                'id' => $this->rejecter?->public_id,
                'name' => $this->rejecter?->name,
            ]),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'resolved_by' => $this->resolver?->public_id,
            'resolver' => $this->whenLoaded('resolver', fn () => [
                'id' => $this->resolver?->public_id,
                'name' => $this->resolver?->name,
            ]),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolution_note' => $this->resolution_note,
            'idempotency_key' => $this->idempotency_key,
            'lines' => MaterialReturnLineResource::collection($this->whenLoaded('lines')),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(function ($event): array {
                /** @var MaterialReturnEvent $event */
                return [
                    'id' => $event->public_id,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'action' => $event->action,
                    'actor_id' => $event->actor?->public_id,
                    'actor_name' => $event->actor->name ?? null,
                    'reason' => $event->reason,
                    'payload' => $event->payload === null ? null : AuditProperties::forDisplay($event->payload),
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
