<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\TenantIndexRequest;
use App\Queries\Platform\PlatformTenantListQuery;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

/** The platform landing page: every tenant (top-level company with its subsidiaries) and how it is doing. */
class DashboardController extends Controller
{
    public function __invoke(TenantIndexRequest $request, PlatformTenantListQuery $tenants): Response
    {
        $filters = $request->filters();

        return Inertia::render('Platform/Dashboard', [
            'tenants' => JsonResource::collection($tenants->paginate($filters)),
            'filters' => $filters,
            'summary' => $tenants->summary(),
        ]);
    }
}
