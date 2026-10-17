<?php

namespace Modules\SPK\Http\Resources\Api\V1;

use App\Models\Core\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Support\TripSteps;

/**
 * What the customer app may see of a technician's visit: an allow-list. Of the technician the first name and nothing
 * else, as in the WhatsApp message; nothing the ISP keeps for itself (title, notes, status words, why work was sent
 * back, items, evidence, POP).
 *
 * @property WorkOrder $resource
 */
class CustomerWorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $wo = $this->resource;
        $address = $wo->subscription?->installationAddress;
        $firstName = $wo->technicianFirstName();

        return [
            'id' => $wo->public_id,
            'code' => $wo->code,
            'type' => ['key' => $wo->type, 'label' => $wo->typeLabel()],
            'scheduled_date' => $wo->scheduled_date?->toDateString(),
            /**
             * `open` while the visit is under way (also while the office sends work back), `done` once the technician has
             * finished (the office's review is not the customer's concern), `cancelled`.
             */
            'state' => match ($wo->status) {
                'cancelled' => 'cancelled',
                'waiting_review', 'completed' => 'done',
                default => 'open',
            },
            /**
             * The four steps of the visit. `current` is the last one reached: it and the steps before it are reached and only
             * those carry a time, which is null for a step that was skipped. Empty for a cancelled visit.
             *
             * @var list<array{key: 'assigned'|'en_route'|'in_progress'|'installed', label: string, at: string|null}>
             */
            'steps' => TripSteps::of($wo),
            /** The last step reached; null when none has (and for a cancelled visit). */
            'current' => TripSteps::current($wo),
            'technician' => $firstName === null ? null : ['first_name' => $firstName],
            'installation_address' => $address instanceof CustomerAddress ? [
                'label' => $address->label,
                'address' => $address->address,
                'city' => $address->city,
            ] : null,
        ];
    }
}
