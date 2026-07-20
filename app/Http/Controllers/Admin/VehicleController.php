<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVehicleRequest;
use App\Http\Requests\Admin\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Core\Vehicle;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VehicleController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Vehicle::class);
        $vehicles = Vehicle::query()->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/Vehicles/Index', [
            'vehicles' => VehicleResource::collection($vehicles),
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        Gate::authorize('store', Vehicle::class);
        $data = $request->validated();
        $data['company_id'] = CompanyService::currentId();
        DB::transaction(function () use ($data) {
            $vehicle = Vehicle::create($data);
            AuditService::log('vehicle', 'created', ['plate' => $vehicle->plate_number], $vehicle);
        });

        return back()->with('success', 'Vehicle created.');
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('update', $vehicle);
        $vehicle->update($request->validated());

        return back()->with('success', 'Vehicle updated.');
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('delete', $vehicle);
        $vehicle->delete();

        return back()->with('success', 'Vehicle deleted.');
    }
}
