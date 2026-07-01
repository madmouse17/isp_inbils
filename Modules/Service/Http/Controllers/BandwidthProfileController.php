<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\StoreBandwidthProfileRequest;
use Modules\Service\Http\Requests\UpdateBandwidthProfileRequest;
use Modules\Service\Http\Resources\BandwidthProfileResource;
use Modules\Service\Models\BandwidthProfile;

class BandwidthProfileController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BandwidthProfile::class);

        return Inertia::render('Admin/Service/BandwidthProfiles/Index', [
            'bandwidthProfiles' => BandwidthProfileResource::collection(BandwidthProfile::query()->orderBy('name')->paginate(15)->withQueryString()),
            'can' => ['create' => $request->user()?->can('service.create') || $request->user()?->can('service.manage')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', BandwidthProfile::class);

        return Inertia::render('Admin/Service/BandwidthProfiles/Create');
    }

    public function store(StoreBandwidthProfileRequest $request): RedirectResponse
    {
        Gate::authorize('store', BandwidthProfile::class);
        BandwidthProfile::query()->create($request->validated());

        return back()->with('success', 'Bandwidth profile created.');
    }

    public function edit(BandwidthProfile $bandwidthProfile): Response
    {
        Gate::authorize('edit', $bandwidthProfile);

        return Inertia::render('Admin/Service/BandwidthProfiles/Edit', ['bandwidthProfile' => new BandwidthProfileResource($bandwidthProfile)]);
    }

    public function update(UpdateBandwidthProfileRequest $request, BandwidthProfile $bandwidthProfile): RedirectResponse
    {
        Gate::authorize('update', $bandwidthProfile);
        $bandwidthProfile->update($request->validated());

        return back()->with('success', 'Bandwidth profile updated.');
    }

    public function destroy(BandwidthProfile $bandwidthProfile): RedirectResponse
    {
        Gate::authorize('delete', $bandwidthProfile);
        $bandwidthProfile->delete();

        return back()->with('success', 'Bandwidth profile deleted.');
    }
}
