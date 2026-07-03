<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'applies_to' => $this->applies_to,
            'is_required' => $this->is_required,
            'expiry_days' => $this->expiry_days,
            'is_active' => $this->is_active,
        ];
    }
}
