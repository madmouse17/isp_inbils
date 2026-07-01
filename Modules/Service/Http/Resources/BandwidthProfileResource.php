<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BandwidthProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'download_mbps' => $this->download_mbps,
            'upload_mbps' => $this->upload_mbps,
            'type' => $this->type,
            'contention_ratio' => $this->contention_ratio,
            'is_active' => $this->is_active,
        ];
    }
}
