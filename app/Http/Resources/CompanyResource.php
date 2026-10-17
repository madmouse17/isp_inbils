<?php

namespace App\Http\Resources;

use App\Models\Core\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'logo' => ($logo = $this->getFirstMedia('logo'))
                ? '/storage/'.$logo->getPathRelativeToRoot()
                : null,
            'address' => $this->address,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'website' => $this->website,
            'brand_color' => $this->brand_color,
            'support_hours' => $this->support_hours,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'settings' => $this->settings ?? [],
            'is_active' => $this->is_active,
        ];
    }
}
