<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyWorkItemResource;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\MyWorkQuery;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        $user = $request->user();
        abort_unless(MyWorkQuery::isEligible($user), 403, 'No operational permission for any domain.');

        $unified = MyWorkQuery::filtered($request);
        $perPage = MyWorkQuery::perPage($request);
        $items = $unified->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/MyWork/Index', [
            'items' => MyWorkItemResource::collection($items),
            'summary' => MyWorkQuery::summary($request),
            'filters' => $request->only([
                'domain', 'status', 'priority', 'search',
                'date_from', 'date_to', 'sort', 'direction', 'per_page',
            ]),
            'domains' => MyWorkQuery::domainOptions($request),
            'canDispatch' => DispatchQueueQuery::isEligible($user),
            'dispatchSummary' => DispatchQueueQuery::isEligible($user) ? DispatchQueueQuery::summary($request) : null,
        ]);
    }
}
