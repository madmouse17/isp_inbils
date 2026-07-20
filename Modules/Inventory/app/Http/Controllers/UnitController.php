<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreUnitRequest;
use Modules\Inventory\Http\Requests\UpdateUnitRequest;
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Unit;

class UnitController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Unit::class);

        $units = Unit::query()->orderBy('name')->paginate(10)->withQueryString();

        return Inertia::render('Admin/Inventory/Units/Index', [
            'units' => UnitResource::collection($units),
            'can' => ['create' => $request->user()?->can('inventory.create') ?? false],
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        Gate::authorize('store', Unit::class);
        Unit::create($request->validated());

        return back()->with('success', 'Unit created.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('update', $unit);
        $unit->update($request->validated());

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        if ($unit->products()->exists()) {
            return back()->withErrors(['unit' => 'Cannot delete unit with products.']);
        }

        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }
}
