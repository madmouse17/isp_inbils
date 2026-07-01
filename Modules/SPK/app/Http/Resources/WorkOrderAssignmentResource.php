<?php

namespace Modules\SPK\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technician_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at,
            'unassigned_at' => $this->unassigned_at,
            'reason' => $this->reason,
            'technician' => new \App\Http\Resources\UserResource($this->whenLoaded('technician')),
        ];
    }
}
