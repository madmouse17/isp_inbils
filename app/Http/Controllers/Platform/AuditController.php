<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Audit\AuditIndexRequest;
use App\Queries\Platform\PlatformAuditQuery;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __invoke(AuditIndexRequest $request, PlatformAuditQuery $audit): Response
    {
        $filters = $request->filters();

        return Inertia::render('Platform/Audit/Index', [
            'activity' => JsonResource::collection($audit->paginate($filters)),
            'filters' => $audit->displayFilters($filters),
            'filterErrors' => $request->filterErrors(),
            'events' => fn () => $audit->events(),
            'tenants' => $audit->tenants(),
            'admins' => $audit->admins(),
        ]);
    }
}
