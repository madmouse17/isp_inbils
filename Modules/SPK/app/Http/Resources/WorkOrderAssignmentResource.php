<?php

namespace Modules\SPK\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'technician_id' => $this->technician?->public_id,
            'assigned_by' => $this->assigner?->public_id,
            'assigned_at' => $this->assigned_at,
            'unassigned_at' => $this->unassigned_at,
            'reason' => $this->reason,
            // A name and an id, like the SPK's own `assignee` and the ticket comments: the user resource would hand every
            // `spk.view` holder each technician's email, 2FA state and roles, and cost a roles query per row.
            'technician' => $this->whenLoaded('technician', fn (User $technician): array => ['id' => $technician->public_id, 'name' => $technician->name]),
        ];
    }
}
