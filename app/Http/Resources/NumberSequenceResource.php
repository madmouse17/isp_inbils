<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NumberSequenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'prefix' => $this->prefix,
            'next_number' => $this->next_number,
            'padding' => $this->padding,
            'year_suffix' => $this->year_suffix,
        ];
    }
}
