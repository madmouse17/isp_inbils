<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeIndexRequest;
use App\Http\Resources\MyWorkItemResource;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\MyWorkQuery;
use App\Services\Core\CompanyService;
use Inertia\Inertia;
use Inertia\Response;

class MyWorkController extends Controller
{
    public function index(DateRangeIndexRequest $request): Response
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        $user = $request->user();
        abort_unless(MyWorkQuery::isEligible($user), 403, 'Anda tidak memiliki izin operasional untuk domain apa pun.');

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
