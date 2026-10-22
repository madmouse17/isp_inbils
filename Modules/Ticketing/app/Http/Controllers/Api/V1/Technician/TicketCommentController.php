<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\EnforceIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Http\Requests\StoreTicketCommentRequest;
use Modules\Ticketing\Http\Resources\Api\V1\TechnicianTicketCommentResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class TicketCommentController extends Controller
{
    public function __invoke(StoreTicketCommentRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->wherePublicId($id)->firstOrFail();
        Gate::authorize('view', $ticket);
        Gate::authorize('comment', $ticket);

        $data = [...$request->validated(), 'idempotency_key' => $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE)];
        $comment = TicketService::addComment($ticket, $data, $request->user()->id)
            ->load('author:id,name');

        return (new TechnicianTicketCommentResource($comment))->response()->setStatusCode(201);
    }
}
