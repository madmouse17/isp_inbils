<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SLATierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'response_time_hours' => $this->response_time_hours,
            'resolution_time_hours' => $this->resolution_time_hours,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'resolution_on_time_rate_30d' => $this->whenHas('resolution_on_time_rate_30d'),
            'response_on_time_rate_30d' => $this->whenHas('response_on_time_rate_30d'),
            'tickets_30d' => $this->whenHas('tickets_30d'),
        ];
    }
}
