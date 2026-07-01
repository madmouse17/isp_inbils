<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\StoreServicePackageRequest;
use Modules\Service\Http\Requests\UpdateServicePackageRequest;
use Modules\Service\Http\Resources\BandwidthProfileResource;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Http\Resources\SLATierResource;
use Modules\Service\Http\Resources\SpeedProfileResource;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

class ServicePackageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $packages = ServicePackage::query()
            ->with(['bandwidthProfile', 'speedProfile', 'slaTier'])
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('sla_tier_id'), fn ($query) => $query->where('sla_tier_id', $request->integer('sla_tier_id')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request) {
                $query->where('code', 'like', '%'.$request->string('search').'%')
                    ->orWhere('name', 'like', '%'.$request->string('search').'%');
            }))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Service/Packages/Index', [
            'servicePackages' => ServicePackageResource::collection($packages),
            'slaTiers' => SLATierResource::collection(SLATier::query()->orderBy('name')->get()),
            'filters' => $request->only(['is_active', 'sla_tier_id', 'search']),
            'can' => ['create' => $request->user()?->can('service.create') || $request->user()?->can('service.manage')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ServicePackage::class);

        return Inertia::render('Admin/Service/Packages/Create', $this->formOptions());
    }

    public function store(StoreServicePackageRequest $request): RedirectResponse
    {
        Gate::authorize('store', ServicePackage::class);
        ServicePackage::query()->create($request->validated());

        return back()->with('success', 'Service package created.');
    }

    public function show(ServicePackage $servicePackage): Response
    {
        Gate::authorize('view', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Show', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier'])),
        ]);
    }

    public function edit(ServicePackage $servicePackage): Response
    {
        Gate::authorize('edit', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Edit', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier'])),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateServicePackageRequest $request, ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('update', $servicePackage);
        $servicePackage->update($request->validated());

        return back()->with('success', 'Service package updated.');
    }

    public function destroy(ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('delete', $servicePackage);

        if ($this->hasActiveSubscriptions($servicePackage)) {
            return back()->withErrors(['service_package' => 'Service package has active subscriptions.']);
        }

        $servicePackage->delete();

        return back()->with('success', 'Service package deleted.');
    }

    public function deactivate(ServicePackage $pkg): RedirectResponse
    {
        Gate::authorize('update', $pkg);
        $pkg->update(['is_active' => false]);

        return back()->with('success', 'Service package deactivated.');
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'bandwidthProfiles' => BandwidthProfileResource::collection(BandwidthProfile::query()->where('is_active', true)->orderBy('name')->get()),
            'speedProfiles' => SpeedProfileResource::collection(SpeedProfile::query()->where('is_active', true)->orderBy('name')->get()),
            'slaTiers' => SLATierResource::collection(SLATier::query()->where('is_active', true)->orderBy('name')->get()),
        ];
    }

    private function hasActiveSubscriptions(ServicePackage $servicePackage): bool
    {
        if (! Schema::hasTable('service_subscriptions')) {
            return false;
        }

        return $servicePackage->subscriptions()
            ->whereIn('status', ['active', 'pending'])
            ->exists();
    }
}
