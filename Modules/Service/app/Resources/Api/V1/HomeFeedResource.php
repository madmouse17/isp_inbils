<?php

namespace Modules\Service\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property array{announcements: mixed, banners: mixed} $resource */
class HomeFeedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'announcements' => CustomerAnnouncementResource::collection($this->resource['announcements']),
            'banners' => CustomerBannerResource::collection($this->resource['banners']),
        ];
    }
}
