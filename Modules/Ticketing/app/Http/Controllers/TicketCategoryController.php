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
        $columns = ['code', 'name', 'default_sla_hours', 'default_response_hours', 'customer_hint', 'is_active'];

        return Inertia::render('Admin/Tickets/Categories/Index', [
            // Not `get(['public_id as id'])`: a model casts `id` to int, which turns a ULID into 1.
            'categories' => TicketCategory::query()->orderBy('name')->get(['public_id', ...$columns])
                ->map(fn (TicketCategory $category): array => ['id' => $category->public_id, ...$category->only($columns)])
                ->all(),
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
