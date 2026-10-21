<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file on a ticket, as the viewer's app sees it. The bytes come from GET media/{id}. `mine` says who uploaded
 * it; `can_delete` is the one flag the delete button follows (uploaded by the viewer and the ticket still open).
 * The ticket resources hand each file its ticket, so listing them costs no query per file; the uploaders of a
 * list are read in one query, since a media row keeps only the integer id of its uploader.
 *
 * @mixin Media
 *
 * @property Media $resource
 */
class TicketAttachmentResource extends JsonResource
{
    /**
     * Scramble cannot follow this override to the framework's collection(), so it is told what the call returns.
     *
     * @scramble-return AnonymousResourceCollection<mixed, array, static>
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        $media = collect($resource);
        $ids = $media->map(fn (Media $item): mixed => $item->getCustomProperty('uploaded_by'))->filter()->unique()->values();
        $uploaders = $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');

        $media->each(fn (Media $item) => $item->setRelation('uploader', $uploaders->get($item->getCustomProperty('uploaded_by'))));

        return parent::collection($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $uploadedBy = $this->resource->getCustomProperty('uploaded_by');
        $mine = $viewer !== null && $uploadedBy !== null && (int) $uploadedBy === (int) $viewer->id;
        $ticket = $this->resource->model;

        return [
            'id' => $this->public_id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            /** @var string|null */
            'caption' => $this->resource->getCustomProperty('caption'),
            /** When the file was added. */
            'uploaded_at' => $this->created_at,
            /** First name of whoever uploaded the file, staff or the customer; null when that account is gone. The rest of the profile stays internal. */
            'uploader_first_name' => $this->uploaderFirstName($uploadedBy),
            /** @var bool */
            'mine' => $mine,
            /** @var bool */
            'can_delete' => $mine && $ticket instanceof Ticket && TicketService::mayRemoveAttachment($viewer, $ticket, $this->resource),
        ];
    }

    private function uploaderFirstName(mixed $uploadedBy): ?string
    {
        if ($uploadedBy === null) {
            return null;
        }

        // A list has its uploaders loaded together (collection()); one file alone asks for its uploader.
        $uploader = $this->resource->relationLoaded('uploader')
            ? $this->resource->getRelation('uploader')
            : User::query()->whereKey($uploadedBy)->first(['id', 'name']);

        return $uploader instanceof User ? Str::of($uploader->name)->trim()->before(' ')->toString() : null;
    }
}
