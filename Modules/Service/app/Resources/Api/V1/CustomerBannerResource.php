<?php

namespace Modules\Service\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Service\Models\PromoBanner;

/** @mixin PromoBanner */
class CustomerBannerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $media = $this->getFirstMedia('promo_banner');

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'alt_text' => $this->alt_text,
            'image_url' => $media === null ? null : route('api.v1.media.show', $media),
            'link_kind' => $this->link_kind,
            'link_target' => $this->link_target,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
        ];
    }
}
