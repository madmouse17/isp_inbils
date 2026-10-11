<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApprovalInboxItemResource;
use App\Queries\Admin\ApprovalInboxQuery;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalInboxController extends Controller
{
    /**
     * Read-only approval inbox (phase 1: SPK waiting_review).
     *
     * The queue only exists for actors who can approve or reject at least one
     * mature domain. Filtering, pagination, sorting and the summary counters
     * are server-side and company-scoped; the frontend never infers
     * eligibility on its own.
     */
    public function index(Request $request): Response
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        $user = $request->user();
        $eligible = ApprovalInboxQuery::isEligible($user);
        abort_unless($eligible, 403, 'No approval permission for any domain.');

        $query = ApprovalInboxQuery::filtered($request);
        $items = $query->paginate(ApprovalInboxQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Approvals/Index', [
            'items' => ApprovalInboxItemResource::collection($items),
            'summary' => ApprovalInboxQuery::summary($request),
            'filters' => $request->only([
                'domain', 'type', 'status', 'creator', 'search',
                'date_from', 'date_to', 'priority', 'sort', 'direction', 'per_page',
            ]),
            'types' => ApprovalInboxQuery::typeOptions($request),
            'requesters' => ApprovalInboxQuery::requesterOptions($request),
        ]);
    }
}
