<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['employee_number', 'status', 'hire_date', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeProfile::class);

        $employees = $this->filteredQuery($request);

        if (! $request->filled('sort') && ! $request->filled('sort_by')) {
            $employees->reorder()->latest();
        }

        $employees = $employees->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Admin/Employees/Index', [
            'employees' => EmployeeResource::collection($employees),
            'organizations' => OrganizationResource::collection(OrganizationUnit::query()->where('is_active', true)->orderBy('code')->get()),
            'vehicles' => VehicleResource::collection(Vehicle::query()->where('is_active', true)->orderBy('plate_number')->get()),
            'users' => UserResource::collection(User::query()->where('is_active', true)->whereDoesntHave('employeeProfile')->orderBy('name')->get()),
            'filters' => $request->only(['search', 'organization_id', 'status', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('employee.manage') ?? false,
                'export' => $request->user()?->can('employee.export') ?? false,
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Gate::authorize('create', EmployeeProfile::class);
        EmployeeProfile::create($request->validated());

        return back()->with('success', 'Employee profile created.');
    }

    public function update(UpdateEmployeeRequest $request, EmployeeProfile $employee): RedirectResponse
    {
        Gate::authorize('update', $employee);
        $employee->update($request->validated());

        return back()->with('success', 'Employee profile updated.');
    }

    public function destroy(EmployeeProfile $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);
        $employee->delete();

        return back()->with('success', 'Employee profile deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('employee.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('employee_number', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'employee_number' => 'Employee Number',
            'name' => 'Name',
            'email' => 'Email',
            'organization' => 'Organization',
            'vehicle' => 'Vehicle',
            'status' => 'Status',
            'phone' => 'Phone',
        ];

        $map = static fn (EmployeeProfile $e): array => [
            'employee_number' => $e->employee_number,
            'name' => $e->user?->name ?? '',
            'email' => $e->user?->email ?? '',
            'organization' => $e->organization?->name ?? '',
            'vehicle' => $e->vehicle?->plate_number ?? '',
            'status' => $e->status,
            'phone' => $e->phone ?? '',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Employees', $columns, $map, "employees-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "employees-export-{$stamp}.csv");
    }

    /**
     * @return Builder<EmployeeProfile>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = EmployeeProfile::query()
            ->with(['user', 'organization', 'vehicle'])
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('employee_number', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhereHas('user', function (Builder $uq) use ($like): void {
                            $uq->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when($request->input('organization_id'), fn (Builder $q, $organizationId) => $q->where('organization_id', $organizationId))
            ->when($request->input('status'), function (Builder $q, string $status): void {
                if (in_array($status, ['active', 'inactive', 'terminated'], true)) {
                    $q->where('status', $status);
                }
            });

        return $this->applySort($query, $request, 'employee_number');
    }
}
