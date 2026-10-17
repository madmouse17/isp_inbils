<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeIndexRequest;
use App\Http\Resources\DispatchItemResource;
use App\Http\Resources\MyWorkItemResource;
use App\Models\User;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\MyWorkQuery;
use App\Rules\PublicIdExists;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DispatchQueueController extends Controller
{
    public function index(DateRangeIndexRequest $request): Response|RedirectResponse
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        $user = $request->user();

        if (! DispatchQueueQuery::isEligible($user)) {
            if (MyWorkQuery::isEligible($user)) {
                return redirect()->route('admin.my-work.index');
            }
            abort(403, 'Anda tidak memiliki izin penugasan untuk domain apa pun.');
        }

        $activeTab = $request->input('tab', 'dispatch');

        if ($activeTab === 'my_work' && MyWorkQuery::isEligible($user)) {
            $unified = MyWorkQuery::filtered($request);
            $perPage = MyWorkQuery::perPage($request);
            $items = $unified->paginate($perPage)->withQueryString();

            return Inertia::render('Admin/Dispatch/Index', [
                'items' => MyWorkItemResource::collection($items),
                'summary' => DispatchQueueQuery::summary($request),
                'myWorkSummary' => MyWorkQuery::summary($request),
                'activeTab' => 'my_work',
                'filters' => $request->only([
                    'domain', 'status', 'priority', 'search',
                    'date_from', 'date_to', 'sort', 'direction', 'per_page', 'tab',
                ]),
                'domains' => MyWorkQuery::domainOptions($request),
                'handlers' => DispatchQueueQuery::eligibleHandlers($request),
                'technicians' => DispatchQueueQuery::eligibleTechnicians($request),
                'canDispatch' => true,
                'canMyWork' => true,
            ]);
        }

        $unified = DispatchQueueQuery::filtered($request);
        $perPage = DispatchQueueQuery::perPage($request);
        $items = $unified->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Dispatch/Index', [
            'items' => DispatchItemResource::collection($items),
            'summary' => DispatchQueueQuery::summary($request),
            'myWorkSummary' => MyWorkQuery::isEligible($user) ? MyWorkQuery::summary($request) : null,
            'activeTab' => 'dispatch',
            'filters' => $request->only([
                'domain', 'status', 'priority', 'search',
                'date_from', 'date_to', 'sort', 'direction', 'per_page', 'tab',
            ]),
            'domains' => DispatchQueueQuery::domainOptions($request),
            'handlers' => DispatchQueueQuery::eligibleHandlers($request),
            'technicians' => DispatchQueueQuery::eligibleTechnicians($request),
            'canDispatch' => true,
            'canMyWork' => MyWorkQuery::isEligible($user),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        $user = $request->user();
        abort_unless(DispatchQueueQuery::isEligible($user), 403, 'Anda tidak memiliki izin penugasan untuk domain apa pun.');

        $assignee = ['nullable', new PublicIdExists(User::class, fn (Builder $query) => $query->where('company_id', $companyId))];

        $request->validate([
            'domain' => ['required', 'string', 'in:ticket,spk'],
            'id' => ['required', 'ulid'],
            'assignee_id' => $assignee,
            'handler_id' => $assignee,
            'technician_id' => $assignee,
            'expected_assigned_to' => ['present', 'nullable', 'ulid'],
            'expected_updated_at' => ['required', 'string', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
        ]);

        $domain = $request->input('domain');
        $id = (string) $request->input('id');
        $assigneeId = PublicId::resolveOne(User::class, $request->input('assignee_id') ?? $request->input('handler_id') ?? $request->input('technician_id'));

        if ($assigneeId === null) {
            throw ValidationException::withMessages(['assignee_id' => 'Penanggung jawab wajib dipilih.']);
        }

        $expectedAssignedTo = PublicId::resolveOne(User::class, $request->input('expected_assigned_to'));
        $expectedUpdatedAt = $request->input('expected_updated_at');

        if ($domain === 'ticket') {
            $ticket = Ticket::forCompany($companyId)->wherePublicId($id)->firstOrFail();
            Gate::authorize('assign', $ticket);
            try {
                TicketService::assign($ticket, $assigneeId, $user->id, $expectedAssignedTo, $expectedUpdatedAt);
            } catch (HttpException $exception) {
                if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                    throw ValidationException::withMessages(['conflict' => $exception->getMessage()]);
                }
                throw $exception;
            }

            return back()->with('success', "Tiket {$ticket->code} berhasil ditugaskan.");
        }

        if ($domain === 'spk') {
            $wo = WorkOrder::forCompany($companyId)->wherePublicId($id)->firstOrFail();
            Gate::authorize('assign', $wo);
            try {
                SpkService::assign($wo, $assigneeId, $user->id, $expectedAssignedTo, $expectedUpdatedAt);
            } catch (HttpException $exception) {
                if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                    throw ValidationException::withMessages(['conflict' => $exception->getMessage()]);
                }
                throw $exception;
            }

            return back()->with('success', "SPK {$wo->code} berhasil ditugaskan.");
        }

        abort(422, 'Domain tidak valid.');
    }
}
