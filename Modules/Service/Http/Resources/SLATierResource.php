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
            'id' => $this->id,
            'name' => $this->name,
            'uptime_pct' => $this->uptime_pct,
            'response_time_hours' => $this->response_time_hours,
            'resolution_time_hours' => $this->resolution_time_hours,
            'credit_pct' => $this->credit_pct,
            'is_active' => $this->is_active,
        ];
    }
}
