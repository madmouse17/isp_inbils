<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /** A media row keeps only the integer id of its uploader: the whole list reads the uploaders in one query. */
    public static function collection($resource): AnonymousResourceCollection
    {
        $media = collect($resource);
        $uploaders = User::query()
            ->whereIn('id', $media->map(fn (Media $item): mixed => $item->getCustomProperty('uploaded_by'))->filter()->unique()->values())
            ->get(['id', 'public_id'])
            ->keyBy('id');

        $media->each(fn (Media $item) => $item->setRelation('uploader', $uploaders->get($item->getCustomProperty('uploaded_by'))));

        return parent::collection($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->getCustomProperty('type'),
            'file_path' => route('admin.media.show', $this->resource, false),
            'url' => route('admin.media.show', $this->resource, false),
            'original_name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size,
            'caption' => $this->getCustomProperty('caption'),
            'uploaded_by' => $this->uploaderPublicId(),
            'created_at' => $this->created_at,
            'uploaded_at' => $this->created_at,
        ];
    }

    private function uploaderPublicId(): ?string
    {
        if ($this->resource->relationLoaded('uploader')) {
            return $this->resource->getRelation('uploader')?->public_id;
        }

        $uploader = $this->getCustomProperty('uploaded_by');

        return $uploader === null ? null : User::query()->whereKey($uploader)->value('public_id');
    }
}
