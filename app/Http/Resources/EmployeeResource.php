<?php

namespace App\Http\Resources;

use App\Models\Core\EmployeeProfile;
use App\Support\OptimisticLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeProfile */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'user_id' => $this->user?->public_id,
            'organization_id' => $this->organization?->public_id,
            'vehicle_id' => $this->vehicle?->public_id,
            'employee_number' => $this->employee_number,
            'phone' => $this->phone,
            'hire_date' => $this->hire_date?->toDateString(),
            'status' => $this->status,
            'skills' => $this->skills,
            'notes' => $this->notes,
            ...$this->lifecycleAttributes(),
            'updated_at' => OptimisticLock::version($this->resource),
            'user' => new UserResource($this->whenLoaded('user')),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
        ];
    }
}
