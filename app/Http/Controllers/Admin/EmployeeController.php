<?php

namespace App\Http\Controllers\Admin;

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
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeProfile::class);
        $employees = EmployeeProfile::query()->with(['user', 'organization', 'vehicle'])
            ->when($request->input('search'), fn ($q, $v) => $q->whereHas('user', fn ($sq) => $sq->where('name', 'like', "%{$v}%")))
            ->when($request->input('organization_id'), fn ($q, $v) => $q->where('organization_id', $v))
            ->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/Employees/Index', [
            'employees' => EmployeeResource::collection($employees),
            'organizations' => OrganizationResource::collection(OrganizationUnit::query()->where('is_active', true)->orderBy('code')->get()),
            'vehicles' => VehicleResource::collection(Vehicle::query()->where('is_active', true)->orderBy('plate_number')->get()),
            'users' => UserResource::collection(User::query()->where('is_active', true)->whereDoesntHave('employeeProfile')->orderBy('name')->get()),
            'filters' => $request->only(['search', 'organization_id']),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Gate::authorize('store', EmployeeProfile::class);
        $data = $request->validated();
        $data['company_id'] = CompanyService::currentId();
        DB::transaction(function () use ($data) {
            $profile = EmployeeProfile::create($data);
            AuditService::log('employee_profile', 'created', ['employee_number' => $profile->employee_number], $profile);
        });

        return back()->with('success', 'Employee profile created.');
    }

    public function update(UpdateEmployeeRequest $request, EmployeeProfile $employee_profile): RedirectResponse
    {
        Gate::authorize('update', $employee_profile);
        DB::transaction(function () use ($request, $employee_profile) {
            $employee_profile->update($request->validated());
            AuditService::log('employee_profile', 'updated', ['employee_number' => $employee_profile->employee_number], $employee_profile);
        });

        return back()->with('success', 'Employee profile updated.');
    }

    public function destroy(EmployeeProfile $employee_profile): RedirectResponse
    {
        Gate::authorize('delete', $employee_profile);
        DB::transaction(function () use ($employee_profile) {
            AuditService::log('employee_profile', 'deleted', ['employee_number' => $employee_profile->employee_number]);
            $employee_profile->delete();
        });

        return back()->with('success', 'Employee profile deleted.');
    }
}
