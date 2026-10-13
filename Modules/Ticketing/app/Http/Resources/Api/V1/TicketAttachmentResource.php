<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file on a ticket, as the viewer's app sees it. The bytes come from GET media/{id}. `mine` says who uploaded
 * it; `can_delete` is the one flag the delete button follows (uploaded by the viewer and the ticket still open).
 * The ticket resources hand each file its ticket, so listing them costs no query per file.
 *
 * @mixin Media
 *
 * @property Media $resource
 */
class TicketAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $uploadedBy = $this->resource->getCustomProperty('uploaded_by');
        $mine = $viewer !== null && $uploadedBy !== null && (int) $uploadedBy === (int) $viewer->id;
        $ticket = $this->resource->model;

        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            /** @var string|null */
            'caption' => $this->resource->getCustomProperty('caption'),
            /** @var bool */
            'mine' => $mine,
            /** @var bool */
            'can_delete' => $mine && $ticket instanceof Ticket && TicketService::mayRemoveAttachment($viewer, $ticket, $this->resource),
        ];
    }
}
