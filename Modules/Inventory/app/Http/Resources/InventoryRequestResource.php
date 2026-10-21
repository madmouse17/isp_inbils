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
            'id' => $this->public_id,
            'code' => $this->code,
            'company_id' => $this->company?->public_id,
            'status' => $this->status,
            'needed_at' => $this->needed_at?->toDateString(),
            'reason' => $this->reason,
            'approval_note' => $this->approval_note,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,

            'warehouse_location_id' => $this->warehouseLocation?->public_id,
            'warehouse_location' => $this->whenLoaded('warehouseLocation', fn () => [
                'id' => $this->warehouseLocation->public_id,
                'name' => $this->warehouseLocation->name,
                'code' => $this->warehouseLocation->code,
            ]),

            'requested_by' => $this->requester?->public_id,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->public_id,
                'name' => $this->requester->name,
                'email' => $this->requester->email,
            ]),

            'approved_by' => $this->approver?->public_id,
            'approver' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver->public_id,
                'name' => $this->approver->name,
            ]),

            'prepared_by' => $this->preparer?->public_id,
            'preparer' => $this->whenLoaded('preparer', fn () => [
                'id' => $this->preparer->public_id,
                'name' => $this->preparer->name,
            ]),

            'ready_by' => $this->readyUser?->public_id,
            'ready_user' => $this->whenLoaded('readyUser', fn () => [
                'id' => $this->readyUser->public_id,
                'name' => $this->readyUser->name,
            ]),

            'issued_by' => $this->issuer?->public_id,
            'issuer' => $this->whenLoaded('issuer', fn () => [
                'id' => $this->issuer->public_id,
                'name' => $this->issuer->name,
            ]),

            'rejected_by' => $this->rejecter?->public_id,
            'rejecter' => $this->whenLoaded('rejecter', fn () => [
                'id' => $this->rejecter->public_id,
                'name' => $this->rejecter->name,
            ]),

            'cancelled_by' => $this->canceller?->public_id,
            'canceller' => $this->whenLoaded('canceller', fn () => [
                'id' => $this->canceller->public_id,
                'name' => $this->canceller->name,
            ]),

            'source_type' => $this->source_type,
            'source_id' => $this->sourcePublicId(),
            // sourcePublicId() has loaded the record by now: the code is what people read, the id only builds the link.
            'source_code' => $this->relationLoaded('source') ? $this->getRelation('source')?->getAttribute('code') : null,

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
