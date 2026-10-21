<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEvaluationRequest;
use App\Http\Requests\Admin\UpdateEvaluationRequest;
use App\Http\Resources\EmployeeEvaluationResource;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Inertia\Response as InertiaResponse;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvaluationController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['evaluated_at', 'score', 'reference_type', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeEvaluation::class);

        $employeeId = PublicId::resolveOne(User::class, $request->query('employee_id'));
        $employeePublicId = $employeeId === null ? null : (string) $request->query('employee_id');
        $employees = $this->filterableEmployees($request);
        $evaluations = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();
        $referenceOptions = $this->referenceOptions($evaluations->getCollection());

        return Inertia::render('Admin/Evaluations/Index', [
            'evaluations' => EmployeeEvaluationResource::collection($evaluations)->additional(['referenceOptions' => $referenceOptions]),
            'employeeOptions' => $this->employeeOptions($request, $employees, $employeePublicId),
            'selectedEmployee' => $this->employeeOption($employees, $employeePublicId),
            'filters' => $request->only([
                'employee_id', 'reference_type', 'status', 'search', 'sort', 'direction', 'per_page',
            ]),
            'can' => [
                'create' => $request->user()?->can('evaluation.create') ?? false,
                'update' => $request->user()?->can('evaluation.update') ?? false,
                'delete' => $request->user()?->can('evaluation.delete') ?? false,
                'export' => $request->user()?->can('evaluation.export') ?? false,
            ],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('viewAny', EmployeeEvaluation::class);
        abort_unless($request->user()?->can('evaluation.export') ?? false, 403);

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('evaluated_at', 'desc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'employee' => 'Karyawan',
            'reference' => 'Referensi',
            'score' => 'Nilai',
            'customer_rating' => 'Penilaian pelanggan',
            'evaluator' => 'Penilai',
            'evaluated_at' => 'Tanggal',
        ];

        $map = static fn (EmployeeEvaluation $evaluation): array => [
            'employee' => $evaluation->employee?->name ?? '',
            'reference' => $evaluation->reference_type.':'.$evaluation->reference_id,
            'score' => $evaluation->score,
            'customer_rating' => $evaluation->customer_rating ?? '',
            'evaluator' => $evaluation->evaluator?->name ?? '',
            'evaluated_at' => $evaluation->evaluated_at,
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Evaluasi', $columns, $map, "evaluations-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "evaluations-export-{$stamp}.csv");
    }

    /** @return Builder<EmployeeEvaluation> */
    private function filteredQuery(Request $request): Builder
    {
        $query = EmployeeEvaluation::query()
            ->with(['employee', 'evaluator'])
            ->when($request->input('employee_id'), fn ($q, $value) => $q->where('employee_id', PublicId::resolveOne(User::class, $value) ?? 0))
            ->when($request->input('reference_type'), fn ($q, $value) => $q->where('reference_type', $value))
            ->when($request->input('search'), fn ($q, $value) => $q->whereHas('employee', fn ($employee) => $employee->where('name', 'like', "%{$value}%")));

        $user = $request->user();

        // Without evaluation.view (only evaluation.view.own) a person reads their own evaluations alone.
        if ($user !== null && ! $user->can('evaluation.view')) {
            $query->where('employee_id', $user->id);
        } else {
            // Management reads the evaluations that stand, or the deleted ones it asks for.
            $query->lifecycleStatus($request->input('status'));
        }

        return $this->applySort($query, $request, 'evaluated_at', 'desc');
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', EmployeeEvaluation::class);

        return Inertia::render('Admin/Evaluations/Create', [
            'employeeOptions' => $this->employeeOptions($request, $this->employeeQuery()),
            'ticketOptions' => SearchOptions::for(
                $request,
                'ticket',
                Ticket::query()
                    ->whereNotNull('assigned_to')
                    ->whereIn('status', ['resolved', 'closed']),
                ['code', 'title'],
                static fn (Ticket $ticket): array => [
                    'value' => $ticket->public_id,
                    'label' => $ticket->code,
                    'description' => $ticket->title,
                ],
            ),
            'workOrderOptions' => SearchOptions::for(
                $request,
                'workOrder',
                WorkOrder::query()
                    ->whereNotNull('assigned_to')
                    ->where('status', 'completed'),
                ['code', 'title'],
                static fn (WorkOrder $workOrder): array => [
                    'value' => $workOrder->public_id,
                    'label' => $workOrder->code,
                    'description' => $workOrder->title,
                ],
            ),
            // Lazy: the option searches reload this page for every keystroke and have no use for it.
            'prefill' => fn (): ?array => $this->ticketPrefill($request),
        ]);
    }

    /**
     * The form as a closed ticket opens it: the ticket and its handler, ready to be rated. Anything else (no ticket asked
     * for, one that is unknown, of another company, unfinished or unassigned) is the plain form, never an error. A handler
     * the form cannot rate (gone, inactive, not evaluable) leaves the employee to be picked.
     *
     * @return array{employee: array{value: string, label: string, description: string}|null, reference_type: 'Ticket', reference: array{value: string, label: string, description: string}}|null
     */
    private function ticketPrefill(Request $request): ?array
    {
        $ticket = Ticket::query()
            ->with('assignee')
            ->wherePublicId($request->query('ticket'))
            ->where('status', 'closed')
            ->whereNotNull('assigned_to')
            ->first();

        if ($ticket === null) {
            return null;
        }

        return [
            'employee' => $this->employeeOption($this->employeeQuery(), $ticket->assignee?->public_id),
            'reference_type' => 'Ticket',
            'reference' => ['value' => $ticket->public_id, 'label' => $ticket->code, 'description' => $ticket->title],
        ];
    }

    public function store(StoreEvaluationRequest $request): RedirectResponse
    {
        Gate::authorize('store', EmployeeEvaluation::class);

        $data = $request->validated();
        $data['evaluator_id'] = $request->user()->id;
        $data['evaluated_at'] = now();

        if ($data['reference_type'] === 'Ticket') {
            $ticket = Ticket::query()->findOrFail($data['reference_id']);
            if ($ticket->first_response_at) {
                $data['first_response_minutes'] = $ticket->created_at->diffInMinutes($ticket->first_response_at);
            }
            if ($ticket->resolved_at) {
                $data['resolution_minutes'] = $ticket->created_at->diffInMinutes($ticket->resolved_at);
            }
        } else {
            $workOrder = WorkOrder::query()->findOrFail($data['reference_id']);
            if ($workOrder->started_at && $workOrder->completed_at) {
                $data['resolution_minutes'] = $workOrder->started_at->diffInMinutes($workOrder->completed_at);
            }
        }

        $evaluation = EmployeeEvaluation::create($data);

        AuditService::log('employee_evaluation', 'created', ['id' => $evaluation->public_id], $evaluation);

        return redirect()->route('admin.evaluations.index')
            ->with('success', 'Evaluasi berhasil dibuat.');
    }

    public function show(EmployeeEvaluation $evaluation): InertiaResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('view', $evaluation);
        $evaluation->load(['employee', 'evaluator']);

        return Inertia::render('Admin/Evaluations/Show', [
            'evaluation' => new EmployeeEvaluationResource($evaluation),
            'referenceOption' => $this->referenceOptions(collect([$evaluation]))[$evaluation->reference_type.':'.$evaluation->referencePublicId()] ?? null,
        ]);
    }

    public function edit(EmployeeEvaluation $evaluation): InertiaResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('update', $evaluation);
        $evaluation->load(['employee', 'evaluator']);

        return Inertia::render('Admin/Evaluations/Edit', [
            'evaluation' => new EmployeeEvaluationResource($evaluation),
        ]);
    }

    public function update(UpdateEvaluationRequest $request, EmployeeEvaluation $evaluation): RedirectResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('update', $evaluation);

        $evaluation->update($request->validated());

        AuditService::log('employee_evaluation', 'updated', ['id' => $evaluation->public_id], $evaluation);

        return redirect()->route('admin.evaluations.index')
            ->with('success', 'Evaluasi berhasil diubah.');
    }

    public function destroy(EmployeeEvaluation $evaluation): RedirectResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('delete', $evaluation);

        $evaluation->delete();

        AuditService::log('employee_evaluation', 'deleted', ['id' => $evaluation->public_id]);

        return back()->with('success', 'Evaluasi berhasil dihapus.');
    }

    /**
     * Eligible employees of the effective company. `User` has no company scope, so the membership table is the
     * tenant boundary; the store request accepts the same people.
     *
     * @return Builder<User>
     */
    private function employeeQuery(): Builder
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        return User::query()
            ->whereIn(
                'users.id',
                DB::table('company_user')
                    ->where('company_id', $companyId)
                    ->select('user_id'),
            )
            ->where('users.is_active', true)
            ->withPermissionIn('evaluation.evaluable', $companyId);
    }

    /**
     * The employees the list's filter may name. Someone without `evaluation.view` reads only their own evaluations (see
     * `filteredQuery`), so the filter holds only themselves: never the names and emails of their colleagues.
     *
     * @return Builder<User>
     */
    private function filterableEmployees(Request $request): Builder
    {
        $employees = $this->employeeQuery();
        $user = $request->user();

        return $user !== null && ! $user->can('evaluation.view') ? $employees->whereKey($user->id) : $employees;
    }

    /** @param  Builder<User>  $employees */
    private function employeeOptions(Request $request, Builder $employees, ?string $selected = null): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'employee',
            $employees,
            ['users.name', 'users.email'],
            static fn (User $user): array => [
                'value' => $user->public_id,
                'label' => $user->name,
                'description' => $user->email,
            ],
            $selected,
        );
    }

    /**
     * @param  Builder<User>  $employees
     * @return array{value: string, label: string, description: string}|null
     */
    private function employeeOption(Builder $employees, ?string $employeeId): ?array
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = (clone $employees)->wherePublicId($employeeId)->first();

        return $employee === null ? null : [
            'value' => $employee->public_id,
            'label' => $employee->name,
            'description' => $employee->email,
        ];
    }

    /**
     * What each evaluation is about, keyed `Type:public_id`: the references load once per type.
     *
     * @param  Collection<int, EmployeeEvaluation>  $evaluations
     * @return array<string, array{code: string, title: string}>
     */
    private function referenceOptions(Collection $evaluations): array
    {
        EmployeeEvaluation::loadReferences($evaluations);

        return $evaluations
            ->filter(fn (EmployeeEvaluation $evaluation): bool => $evaluation->getRelation('reference') !== null)
            ->mapWithKeys(fn (EmployeeEvaluation $evaluation): array => [
                $evaluation->reference_type.':'.$evaluation->getRelation('reference')->public_id => [
                    'code' => $evaluation->getRelation('reference')->code,
                    'title' => $evaluation->getRelation('reference')->title,
                ],
            ])
            ->all();
    }

    private function ensureSameCompany(EmployeeEvaluation $evaluation): void
    {
        RecordNotFound::abortUnless($evaluation->company_id === CompanyService::currentId());
    }
}
