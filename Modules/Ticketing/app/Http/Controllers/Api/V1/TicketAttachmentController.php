<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\EnforceIdempotency;
use App\Http\Middleware\EnsureCustomerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Ticketing\Http\Requests\Api\V1\StoreTicketAttachmentRequest;
use Modules\Ticketing\Http\Resources\Api\V1\TicketAttachmentResource;
use Modules\Ticketing\Services\TicketService;

/** Files on a ticket, for both apps: the customer app reaches its own tickets, the technician app its assigned ones. */
class TicketAttachmentController extends Controller
{
    public function store(StoreTicketAttachmentRequest $request): JsonResponse
    {
        $ticket = $request->ticket();
        $media = TicketService::addAttachment(
            $ticket,
            $request->user(),
            $request->safe()->only(['file', 'caption']),
            $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE),
        );
        $media->setRelation('model', $ticket);

        return (new TicketAttachmentResource($media))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $id, int $media): Response
    {
        $ticket = TicketService::findForApp($id, $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE));
        TicketService::removeAttachment($ticket, $media, $request->user());

        return response()->noContent();
    }
}
