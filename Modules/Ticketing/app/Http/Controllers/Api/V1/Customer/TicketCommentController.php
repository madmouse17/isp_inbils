<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Http\Requests\StoreTicketCommentRequest;
use Modules\Ticketing\Http\Resources\Api\V1\CustomerTicketCommentResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class TicketCommentController extends Controller
{
    public function __invoke(StoreTicketCommentRequest $request, string $id): JsonResponse
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        $ticket = Ticket::query()->where('customer_id', $customer->id)->wherePublicId($id)->firstOrFail();
        Gate::authorize('view', $ticket);
        Gate::authorize('comment', $ticket);

        $comment = TicketService::addCustomerReply($ticket, $request->validated(), $request->user())
            ->load('author:id,name');

        return (new CustomerTicketCommentResource($comment))->response()->setStatusCode(201);
    }
}
