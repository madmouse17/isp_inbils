<?php

namespace Modules\SPK\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Media */
class EvidenceResource extends JsonResource
{
    public function __construct(Media $media, private readonly ?User $uploader = null)
    {
        parent::__construct($media);
    }

    /**
     * @param  iterable<int, Media>  $media
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $media, User $viewer): array
    {
        $collection = $media instanceof Collection ? $media : collect($media);
        $uploaders = User::query()
            ->whereIn('id', $collection->map(fn (Media $item): mixed => $item->getCustomProperty('uploaded_by'))->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return $collection
            ->map(fn (Media $item): array => (new self($item, $uploaders->get($item->getCustomProperty('uploaded_by'))))->forUser($viewer))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();

        return $this->forUser($viewer);
    }

    /** @return array<string, mixed> */
    public function forUser(?User $viewer): array
    {
        $uploadedBy = $this->resource->getCustomProperty('uploaded_by');

        return [
            'id' => $this->id,
            /** @var string|null */
            'type' => $this->getCustomProperty('type'),
            /** @var string|null */
            'caption' => $this->getCustomProperty('caption'),
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            /** @var string|null */
            'captured_at' => $this->getCustomProperty('captured_at'),
            'uploaded_by' => $uploadedBy === null ? null : [
                'id' => (int) $uploadedBy,
                'name' => $this->uploader?->name,
            ],
            'mine' => $viewer !== null && (int) $uploadedBy === (int) $viewer->id,
            'created_at' => $this->created_at,
        ];
    }
}
