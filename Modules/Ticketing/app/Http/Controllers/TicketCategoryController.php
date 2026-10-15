<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Ticketing\Http\Requests\UpdateTicketCategoryRequest;
use Modules\Ticketing\Models\TicketCategory;

class TicketCategoryController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', TicketCategory::class);

        return Inertia::render('Admin/Tickets/Categories/Index', [
            'categories' => TicketCategory::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'default_sla_hours', 'default_response_hours', 'customer_hint', 'is_active']),
            'can' => ['manage' => (bool) $request->user()?->can('ticket.category.manage')],
        ]);
    }

    public function update(UpdateTicketCategoryRequest $request, TicketCategory $ticketCategory): RedirectResponse
    {
        Gate::authorize('update', $ticketCategory);
        $ticketCategory->update($request->validated());

        return back()->with('success', 'Kategori tiket diperbarui.');
    }
}
