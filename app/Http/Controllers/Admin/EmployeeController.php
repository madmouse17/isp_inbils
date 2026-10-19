<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\VehicleResource;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['employee_number', 'status', 'hire_date', 'created_at'];

    /** Stored `status` as the export says it (`lib/statuses/people.ts` EMPLOYEE_STATUS on screen). */
    private const STATUS_LABELS = [
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'terminated' => 'Berhenti',
    ];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeProfile::class);

        $employees = $this->filteredQuery($request);

        if (! $request->filled('sort') && ! $request->filled('sort_by')) {
            $employees->reorder()->latest();
        }

        $employees = $employees->paginate($this->perPage($request))->withQueryString();
        $canManage = $request->user()?->can('employee.manage') ?? false;

        return Inertia::render('Admin/Employees/Index', [
            'employees' => EmployeeResource::collection($employees),
            'organizations' => OrganizationResource::collection(OrganizationUnit::query()->where('is_active', true)->orderBy('code')->get()),
            'vehicles' => VehicleResource::collection(Vehicle::query()->where('is_active', true)->orderBy('plate_number')->get()),
            'userOptions' => SearchOptions::for(
                $request,
                'user',
                User::query()
                    ->where(StoreEmployeeRequest::usersOf(CompanyService::currentId()))
                    ->where('users.is_active', true)
                    ->whereDoesntHave('employeeProfile'),
                ['users.name', 'users.email'],
                fn (User $user): array => ['value' => $user->public_id, 'label' => $user->name, 'description' => $user->email],
            ),
            'filters' => $request->only(['search', 'organization_id', 'status', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $canManage,
                'update' => $canManage,
                'delete' => $canManage,
                'export' => $request->user()?->can('employee.export') ?? false,
            ],
            'codeSequence' => [
                'manual' => NumberSequenceService::allowsManual('employee'),
                'preview' => NumberSequenceService::peek('employee'),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Gate::authorize('create', EmployeeProfile::class);
        DB::transaction(function () use ($request): void {
            $data = $request->validated();
            $data['employee_number'] = NumberSequenceService::resolve('employee', $data['employee_number'] ?? null, 'employee_number');
            EmployeeProfile::create($data);
        });

        return back()->with('success', 'Karyawan dibuat.');
    }

    public function update(UpdateEmployeeRequest $request, EmployeeProfile $employee): RedirectResponse
    {
        Gate::authorize('update', $employee);
        DB::transaction(function () use ($request, $employee): void {
            $locked = EmployeeProfile::query()->lockForUpdate()->findOrFail($employee->id);
            OptimisticLock::assert($locked, $request->string(OptimisticLock::FIELD)->toString());
            $locked->update($request->safe()->except(OptimisticLock::FIELD));
        });

        return back()->with('success', 'Karyawan diperbarui.');
    }

    public function destroy(EmployeeProfile $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);
        $employee->delete();

        return back()->with('success', 'Karyawan dihapus.');
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
            'employee_number' => 'Nomor karyawan',
            'name' => 'Nama',
            'email' => 'Email',
            'organization' => 'Organisasi',
            'vehicle' => 'Kendaraan',
            'status' => 'Status',
            'phone' => 'Telepon',
        ];

        $map = static fn (EmployeeProfile $e): array => [
            'employee_number' => $e->employee_number,
            'name' => $e->user?->name ?? '',
            'email' => $e->user?->email ?? '',
            'organization' => $e->organization?->name ?? '',
            'vehicle' => $e->vehicle?->plate_number ?? '',
            'status' => self::STATUS_LABELS[$e->status] ?? $e->status,
            'phone' => $e->phone ?? '',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Karyawan', $columns, $map, "employees-export-{$stamp}.pdf");
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
            ->when($request->input('organization_id'), fn (Builder $q, $organizationId) => $q->where('organization_id', PublicId::resolveOne(OrganizationUnit::class, $organizationId) ?? 0))
            ->lifecycleStatus($request->input('status'));

        return $this->applySort($query, $request, 'employee_number');
    }
}
