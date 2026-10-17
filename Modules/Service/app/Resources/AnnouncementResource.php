<?php

namespace Modules\Service\Resources;

use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Service\Models\Announcement;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $timezone = CompanyService::current()?->timezone ?: (string) config('app.timezone');

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'kind' => $this->kind,
            'audience' => $this->audience,
            'status' => $this->status(),
            'starts_at' => $this->starts_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            'published_at' => $this->published_at,
            'location_targets' => $this->whenLoaded('locations', fn () => $this->locations->map(fn ($location): array => ['id' => $location->public_id, 'code' => $location->code, 'name' => $location->name])->values()),
            'package_category_targets' => $this->whenLoaded('servicePackageCategories', fn () => $this->servicePackageCategories->map(fn ($category): array => ['id' => $category->public_id, 'code' => $category->code, 'name' => $category->name])->values()),
        ];
    }
}
