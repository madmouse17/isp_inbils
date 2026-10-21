<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Http\Requests\Api\V1\ConfirmCustomerTicketRequest;
use Modules\Ticketing\Http\Requests\Api\V1\ListCustomerTicketsRequest;
use Modules\Ticketing\Http\Requests\Api\V1\StoreCustomerTicketRequest;
use Modules\Ticketing\Http\Resources\Api\V1\CustomerTicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class TicketController extends Controller
{
    public function index(ListCustomerTicketsRequest $request): AnonymousResourceCollection
    {
        $query = $this->ticketsFor($request)
            ->with(['category:id,public_id,name', 'subscription:id,public_id,code', 'assignee:id,name'])
            ->latest('created_at')
            ->latest('id');

        match ($request->validated('status') ?? 'all') {
            'active' => $query->whereIn('status', ['open', 'assigned', 'on_progress']),
            'closed' => $query->whereIn('status', ['resolved', 'closed']),
            default => null,
        };

        return CustomerTicketResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreCustomerTicketRequest $request): JsonResponse
    {
        Gate::authorize('store', Ticket::class);

        $ticket = TicketService::create($request->ticketAttributes(), $request->user()->id)
            ->load(['category:id,public_id,name', 'subscription:id,public_id,code', 'assignee:id,name', 'comments.author:id,name', 'media']);

        return (new CustomerTicketResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $id): CustomerTicketResource
    {
        $ticket = $this->ticketsFor($request)
            ->with($this->detailRelations())
            ->wherePublicId($id)
            ->firstOrFail();

        Gate::authorize('view', $ticket);

        return new CustomerTicketResource($ticket);
    }

    /**
     * "Masalah sudah beres?" (K14): the customer rates a resolved ticket, which closes it, or a closed one that was never rated.
     * Only the customer's own ticket (a stranger's id is a 404) and only with the permission to rate. The answer is the ticket as the
     * detail shows it, so the app redraws from it.
     */
    public function confirmation(ConfirmCustomerTicketRequest $request, string $id): CustomerTicketResource
    {
        $ticket = $this->ticketsFor($request)->wherePublicId($id)->firstOrFail();
        Gate::authorize('confirm', $ticket);

        $confirmed = TicketService::confirmByCustomer($ticket, $request->integer('rating'), $request->validated('comment'));

        return new CustomerTicketResource($confirmed->load($this->detailRelations()));
    }

    /** @return array<int|string, mixed> */
    private function detailRelations(): array
    {
        return [
            'category:id,public_id,name',
            'subscription:id,public_id,code',
            'assignee:id,name',
            'comments' => fn ($query) => $query->where('is_internal', false)->orderBy('created_at')->orderBy('id'),
            'comments.author:id,name',
            'media',
        ];
    }

    /** @return Builder<Ticket> */
    private function ticketsFor(Request $request): Builder
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        return Ticket::query()->where('customer_id', $customer->id);
    }
}
