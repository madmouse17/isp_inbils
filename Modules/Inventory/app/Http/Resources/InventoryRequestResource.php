<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'company_id' => $this->company_id,
            'status' => $this->status,
            'needed_at' => $this->needed_at?->toDateString(),
            'reason' => $this->reason,
            'approval_note' => $this->approval_note,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,

            'warehouse_location_id' => $this->warehouse_location_id,
            'warehouse_location' => $this->whenLoaded('warehouseLocation', fn () => [
                'id' => $this->warehouseLocation->id,
                'name' => $this->warehouseLocation->name,
                'code' => $this->warehouseLocation->code,
            ]),

            'requested_by' => $this->requested_by,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                'email' => $this->requester->email,
            ]),

            'approved_by' => $this->approved_by,
            'approver' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ]),

            'prepared_by' => $this->prepared_by,
            'preparer' => $this->whenLoaded('preparer', fn () => [
                'id' => $this->preparer->id,
                'name' => $this->preparer->name,
            ]),

            'ready_by' => $this->ready_by,
            'ready_user' => $this->whenLoaded('readyUser', fn () => [
                'id' => $this->readyUser->id,
                'name' => $this->readyUser->name,
            ]),

            'issued_by' => $this->issued_by,
            'issuer' => $this->whenLoaded('issuer', fn () => [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
            ]),

            'rejected_by' => $this->rejected_by,
            'rejecter' => $this->whenLoaded('rejecter', fn () => [
                'id' => $this->rejecter->id,
                'name' => $this->rejecter->name,
            ]),

            'cancelled_by' => $this->cancelled_by,
            'canceller' => $this->whenLoaded('canceller', fn () => [
                'id' => $this->canceller->id,
                'name' => $this->canceller->name,
            ]),

            'source_type' => $this->source_type,
            'source_id' => $this->source_id,

            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'prepared_at' => $this->prepared_at?->toISOString(),
            'ready_at' => $this->ready_at?->toISOString(),
            'issued_at' => $this->issued_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            'items' => InventoryRequestItemResource::collection($this->whenLoaded('items')),

            'can' => [
                'view' => $user ? $user->can('view', $this->resource) : false,
                'update' => $user ? $user->can('update', $this->resource) : false,
                'submit' => $user ? $user->can('submit', $this->resource) : false,
                'cancel' => $user ? $user->can('cancel', $this->resource) : false,
                'approve' => $user ? $user->can('approve', $this->resource) : false,
                'reject' => $user ? $user->can('reject', $this->resource) : false,
                'prepare' => $user ? $user->can('prepare', $this->resource) : false,
                'ready' => $user ? $user->can('ready', $this->resource) : false,
                'issue' => $user ? $user->can('issue', $this->resource) : false,
            ],
        ];
    }
}
