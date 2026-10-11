<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Http\Resources\Api\V1\TicketCategoryResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;

class TicketCategoryController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        Gate::authorize('create', Ticket::class);

        return TicketCategoryResource::collection(
            TicketCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }
}
