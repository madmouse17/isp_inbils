<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Core\OrganizationUnit;
use App\Services\Core\CompanyService;
use App\Services\Core\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrganizationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', OrganizationUnit::class);
        $units = OrganizationUnit::query()
            ->withCount('children')
            ->orderBy('path')
            ->orderBy('code')
            ->paginate(10)
            ->withQueryString();

        $parentOptions = OrganizationUnit::query()
            ->select(['id', 'code', 'name', 'type', 'path'])
            ->where('is_active', true)
            ->orderBy('path')
            ->orderBy('code')
            ->get();

        return Inertia::render('Admin/Organizations/Index', [
            'organizations' => OrganizationResource::collection($units),
            'parentOptions' => OrganizationResource::collection($parentOptions),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        Gate::authorize('store', OrganizationUnit::class);
        $data = $request->validated();
        $data['company_id'] = CompanyService::currentId();
        OrganizationService::create($data);

        return back()->with('success', 'Organization unit created.');
    }

    public function update(UpdateOrganizationRequest $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('update', $organization_unit);
        OrganizationService::update($organization_unit, $request->validated());

        return back()->with('success', 'Organization unit updated.');
    }

    public function move(Request $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('update', $organization_unit);
        $request->validate(['parent_id' => ['nullable', 'exists:organization_units,id']]);
        OrganizationService::move($organization_unit, $request->integer('parent_id') ?: 0);

        return back()->with('success', 'Organization unit moved.');
    }

    public function destroy(OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('delete', $organization_unit);
        OrganizationService::delete($organization_unit);

        return back()->with('success', 'Organization unit deleted.');
    }
}
