<?php

namespace Modules\SPK\Http\Resources\Api\V1;

use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;

/**
 * What the technician app may see of an SPK: an allow-list, never the admin resource.
 *
 * @property WorkOrder $resource
 */
class TechnicianWorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $wo = $this->resource;
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $wo->id,
            'code' => $wo->code,
            'type' => $wo->type,
            'title' => $wo->title,
            'description' => $wo->description,
            'status' => $wo->status,
            'priority' => $wo->priority,
            'scheduled_date' => $wo->scheduled_date?->toDateString(),
            'started_at' => $wo->started_at,
            'rejection_reason' => $wo->rejection_reason,
            'ticket' => $this->ticketBlock(),
            'customer' => $wo->customer === null ? null : [
                'id' => $wo->customer->id,
                'code' => $wo->customer->code,
                'name' => $wo->customer->name,
                'phone' => $wo->customer->phone,
            ],
            'subscription' => $wo->subscription === null ? null : [
                'id' => $wo->subscription->id,
                'code' => $wo->subscription->code,
                'package_name' => $wo->subscription->servicePackage?->name,
            ],
            'site' => $this->siteBlock(),
            'items' => $wo->items->map(fn (WorkOrderItem $item): array => [
                'id' => $item->id,
                /** @var string|null */
                'product_name' => data_get($item, 'product.name'),
                /** @var string|null */
                'unit' => data_get($item, 'product.unit.symbol') ?? data_get($item, 'product.unit.name'),
                'quantity_reserved' => (float) $item->quantity_reserved,
                'quantity_used' => (float) $item->quantity_used,
                'note' => $item->note,
            ])->values()->all(),
            'evidence' => $user === null ? [] : EvidenceResource::many($wo->getMedia('evidence'), $user),
            'material_returns' => $this->materialReturns(),
            'actions' => [
                'start' => $user?->can('start', $wo) ?? false,
                'upload_evidence' => $user?->can('uploadEvidence', $wo) ?? false,
                'submit' => $user?->can('submit', $wo) ?? false,
            ],
        ];
    }

    /** @return array{id: int, code: string|null}|null */
    private function ticketBlock(): ?array
    {
        if ($this->resource->ticket_id === null) {
            return null;
        }

        $ticket = DB::table('tickets')
            ->where('company_id', $this->resource->company_id)
            ->where('id', $this->resource->ticket_id)
            ->whereNull('deleted_at')
            ->first(['id', 'code']);

        return $ticket === null ? null : ['id' => (int) $ticket->id, 'code' => $ticket->code];
    }

    /** @return array{label: string|null, address: string|null, lat: float|null, lng: float|null}|null */
    private function siteBlock(): ?array
    {
        $location = $this->resource->location;
        if ($location instanceof Location) {
            return [
                'label' => $location->name,
                'address' => $location->address,
                'lat' => $location->lat === null ? null : (float) $location->lat,
                'lng' => $location->lng === null ? null : (float) $location->lng,
            ];
        }

        $address = $this->resource->subscription?->installationAddress;
        if (! $address instanceof CustomerAddress) {
            return null;
        }

        return [
            'label' => $address->label,
            'address' => collect([$address->address, $address->city])->filter()->join(', '),
            'lat' => $address->lat === null ? null : (float) $address->lat,
            'lng' => $address->lng === null ? null : (float) $address->lng,
        ];
    }

    /** @return list<array{id: int, status: string}> */
    private function materialReturns(): array
    {
        return MaterialReturnCase::withoutCompany()
            ->where('company_id', $this->resource->company_id)
            ->whereHas('inventoryRequest', function ($query): void {
                $query->where('source_type', 'work_order')->where('source_id', $this->resource->id);
            })
            ->orderBy('id')
            ->get(['id', 'status'])
            ->map(fn (MaterialReturnCase $case): array => ['id' => $case->id, 'status' => $case->status])
            ->values()
            ->all();
    }
}
