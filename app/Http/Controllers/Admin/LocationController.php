<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use App\Services\Core\LocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Location::class);

        return Inertia::render('Admin/Locations/Index', [
            'locations' => LocationResource::collection(LocationService::tree()),
            'can' => [
                'create' => $request->user()?->can('location.create') ?? false,
                'update' => $request->user()?->can('location.update') ?? false,
                'delete' => $request->user()?->can('location.delete') ?? false,
            ],
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        Gate::authorize('store', Location::class);

        LocationService::create($request->validated());

        return back()->with('success', 'Location created.');
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        Gate::authorize('update', $location);

        LocationService::update($location, $request->validated());

        return back()->with('success', 'Location updated.');
    }

    public function move(Request $request, Location $location): RedirectResponse
    {
        Gate::authorize('move', $location);

        $data = $request->validate([
            'new_parent_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', CompanyService::currentId())],
        ]);

        LocationService::move($location, (int) $data['new_parent_id']);

        return back()->with('success', 'Location moved.');
    }

    public function destroy(Location $location): RedirectResponse
    {
        Gate::authorize('delete', $location);

        LocationService::delete($location);

        return back()->with('success', 'Location deleted.');
    }
}
