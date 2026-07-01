<?php

namespace Modules\Ticketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'default_sla_hours' => $this->default_sla_hours,
            'default_priority' => $this->default_priority,
            'is_active' => $this->is_active,
        ];
    }
}
