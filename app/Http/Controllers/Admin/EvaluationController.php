<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEvaluationRequest;
use App\Http\Requests\Admin\UpdateEvaluationRequest;
use App\Http\Resources\EmployeeEvaluationResource;
use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EvaluationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeEvaluation::class);

        $evals = EmployeeEvaluation::query()
            ->with(['employee', 'evaluator'])
            ->when($request->input('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->input('reference_type'), fn ($q, $v) => $q->where('reference_type', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->whereHas('employee', fn ($sq) => $sq->where('name', 'like', "%{$v}%")))
            ->latest('evaluated_at')
            ->paginate(15)
            ->withQueryString();

        // Technician sees only own
        if ($request->user()?->hasRole('technician')) {
            $evals->getQuery()->where('employee_id', $request->user()->id);
        }

        return Inertia::render('Admin/Evaluations/Index', [
            'evaluations' => EmployeeEvaluationResource::collection($evals),
            'employees' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['technician', 'staff', 'noc']))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['employee_id', 'reference_type', 'search']),
            'can' => ['create' => $request->user()?->can('evaluation.create') ?? false],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', EmployeeEvaluation::class);

        return Inertia::render('Admin/Evaluations/Create', [
            'employees' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['technician', 'staff', 'noc']))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
        ]);
    }

    public function store(StoreEvaluationRequest $request): RedirectResponse
    {
        Gate::authorize('store', EmployeeEvaluation::class);

        $data = $request->validated();
        $data['evaluator_id'] = $request->user()->id;
        $data['evaluated_at'] = now();

        // Snapshot FRT/resolution from reference
        if ($data['reference_type'] === 'Ticket') {
            $ticket = \Modules\Ticketing\Models\Ticket::find($data['reference_id']);
            if (!$ticket) {
                return back()->withErrors(['reference_id' => 'Ticket not found.'])->withInput();
            }
            if ($ticket->first_response_at) {
                $data['first_response_minutes'] = $ticket->created_at->diffInMinutes($ticket->first_response_at);
            }
            if ($ticket->resolved_at) {
                $data['resolution_minutes'] = $ticket->created_at->diffInMinutes($ticket->resolved_at);
            }
        } elseif ($data['reference_type'] === 'WorkOrder') {
            $wo = \Modules\SPK\Models\WorkOrder::find($data['reference_id']);
            if (!$wo) {
                return back()->withErrors(['reference_id' => 'WorkOrder not found.'])->withInput();
            }
            if ($wo->started_at && $wo->completed_at) {
                $data['resolution_minutes'] = $wo->started_at->diffInMinutes($wo->completed_at);
            }
        }

        $eval = EmployeeEvaluation::create($data);

        AuditService::log('employee_evaluation', 'created', ['id' => $eval->id], $eval);

        return redirect()->route('admin.evaluations.show', $eval)
            ->with('success', 'Evaluation created.');
    }

    public function show(EmployeeEvaluation $evaluation): InertiaResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('view', $evaluation);
        $evaluation->load(['employee', 'evaluator']);

        return Inertia::render('Admin/Evaluations/Show', [
            'evaluation' => new EmployeeEvaluationResource($evaluation),
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

        AuditService::log('employee_evaluation', 'updated', ['id' => $evaluation->id], $evaluation);

        return back()->with('success', 'Evaluation updated.');
    }

    public function destroy(EmployeeEvaluation $evaluation): RedirectResponse
    {
        $this->ensureSameCompany($evaluation);
        Gate::authorize('delete', $evaluation);

        $evaluation->delete();

        AuditService::log('employee_evaluation', 'deleted', ['id' => $evaluation->id]);

        return back()->with('success', 'Evaluation deleted.');
    }

    private function ensureSameCompany(EmployeeEvaluation $evaluation): void
    {
        abort_unless($evaluation->company_id === \App\Services\Core\CompanyService::currentId(), 404);
    }
}
