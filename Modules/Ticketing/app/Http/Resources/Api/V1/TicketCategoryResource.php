<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticketing\Models\TicketCategory;

/** @mixin TicketCategory */
class TicketCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            /** A short tip the ISP wrote for this category ("Saran untuk pelanggan"), at most 500 characters; null when it wrote none. */
            'customer_hint' => $this->customer_hint,
        ];
    }
}
