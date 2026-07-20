<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\StoreSpeedProfileRequest;
use Modules\Service\Http\Requests\UpdateSpeedProfileRequest;
use Modules\Service\Http\Resources\SpeedProfileResource;
use Modules\Service\Models\SpeedProfile;

class SpeedProfileController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SpeedProfile::class);

        return Inertia::render('Admin/Service/SpeedProfiles/Index', [
            'speedProfiles' => SpeedProfileResource::collection(SpeedProfile::query()->orderBy('name')->paginate(10)->withQueryString()),
            'can' => ['create' => $request->user()?->can('service.create') || $request->user()?->can('service.manage')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SpeedProfile::class);

        return Inertia::render('Admin/Service/SpeedProfiles/Create');
    }

    public function store(StoreSpeedProfileRequest $request): RedirectResponse
    {
        Gate::authorize('store', SpeedProfile::class);
        SpeedProfile::query()->create($request->validated());

        return redirect()->route('admin.speed-profiles.index')->with('success', 'Speed profile created.');
    }

    public function edit(SpeedProfile $speedProfile): Response
    {
        Gate::authorize('edit', $speedProfile);

        return Inertia::render('Admin/Service/SpeedProfiles/Edit', ['speedProfile' => new SpeedProfileResource($speedProfile)]);
    }

    public function update(UpdateSpeedProfileRequest $request, SpeedProfile $speedProfile): RedirectResponse
    {
        Gate::authorize('update', $speedProfile);
        $speedProfile->update($request->validated());

        return redirect()->route('admin.speed-profiles.index')->with('success', 'Speed profile updated.');
    }

    public function destroy(SpeedProfile $speedProfile): RedirectResponse
    {
        Gate::authorize('delete', $speedProfile);
        $speedProfile->delete();

        return back()->with('success', 'Speed profile deleted.');
    }
}
