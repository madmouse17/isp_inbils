<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeedProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'download_max_mbps' => $this->download_max_mbps,
            'upload_max_mbps' => $this->upload_max_mbps,
            'burst_download_mbps' => $this->burst_download_mbps,
            'burst_upload_mbps' => $this->burst_upload_mbps,
            'radius_profile_name' => $this->radius_profile_name,
            'is_active' => $this->is_active,
        ];
    }
}
