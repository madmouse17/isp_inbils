<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\StoreSLATierRequest;
use Modules\Service\Http\Requests\UpdateSLATierRequest;
use Modules\Service\Http\Resources\SLATierResource;
use Modules\Service\Models\SLATier;

class SLATierController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SLATier::class);

        return Inertia::render('Admin/Service/SLATiers/Index', [
            'slaTiers' => SLATierResource::collection(SLATier::query()->orderBy('name')->paginate(15)->withQueryString()),
            'can' => ['create' => $request->user()?->can('service.create') || $request->user()?->can('service.manage')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SLATier::class);

        return Inertia::render('Admin/Service/SLATiers/Create');
    }

    public function store(StoreSLATierRequest $request): RedirectResponse
    {
        Gate::authorize('store', SLATier::class);
        SLATier::query()->create($request->validated());

        return back()->with('success', 'SLA tier created.');
    }

    public function edit(SLATier $slaTier): Response
    {
        Gate::authorize('edit', $slaTier);

        return Inertia::render('Admin/Service/SLATiers/Edit', ['slaTier' => new SLATierResource($slaTier)]);
    }

    public function update(UpdateSLATierRequest $request, SLATier $slaTier): RedirectResponse
    {
        Gate::authorize('update', $slaTier);
        $slaTier->update($request->validated());

        return back()->with('success', 'SLA tier updated.');
    }

    public function destroy(SLATier $slaTier): RedirectResponse
    {
        Gate::authorize('delete', $slaTier);
        $slaTier->delete();

        return back()->with('success', 'SLA tier deleted.');
    }
}
