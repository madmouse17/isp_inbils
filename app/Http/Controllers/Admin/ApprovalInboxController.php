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
     * Approval inbox (SPK waiting_review and inventory requests waiting for an approver).
     *
     * The queue only exists for actors who can approve or reject at least one
     * mature domain. Filtering, pagination, sorting and the summary counters
     * are server-side and company-scoped; the frontend never infers
     * eligibility on its own.
     *
     * The list props are closures, so the partial reload that only asks for `review` (the sheet opening) skips them.
     * `review` is the detail of one row, authorised exactly like the row itself, and is never sent unless asked for.
     */
    public function index(Request $request): Response
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        $user = $request->user();
        $eligible = ApprovalInboxQuery::isEligible($user);
        abort_unless($eligible, 403, 'Anda tidak memiliki izin persetujuan untuk domain apa pun.');

        return Inertia::render('Admin/Approvals/Index', [
            'items' => fn () => ApprovalInboxItemResource::collection(
                ApprovalInboxQuery::filtered($request)->paginate(ApprovalInboxQuery::perPage($request))->withQueryString()
            ),
            'summary' => fn () => ApprovalInboxQuery::summary($request),
            'filters' => fn () => $request->only([
                'domain', 'type', 'status', 'creator', 'search',
                'date_from', 'date_to', 'priority', 'sort', 'direction', 'per_page',
            ]),
            'types' => fn () => ApprovalInboxQuery::typeOptions($request),
            'requesters' => fn () => ApprovalInboxQuery::requesterOptions($request),
            'review' => Inertia::optional(fn () => ApprovalInboxQuery::review($request, $request->query('review'))),
        ]);
    }
}
