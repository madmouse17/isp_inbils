<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'vehicle_id' => $this->vehicle_id,
            'employee_number' => $this->employee_number,
            'phone' => $this->phone,
            'hire_date' => $this->hire_date,
            'status' => $this->status,
            'skills' => $this->skills,
            'notes' => $this->notes,
            'user' => new UserResource($this->whenLoaded('user')),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
        ];
    }
}
