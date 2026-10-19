<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['user_id' => User::class, 'organization_id' => OrganizationUnit::class, 'vehicle_id' => Vehicle::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('employee.manage') ?? false;
    }

    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            // bail: `unique` would answer for a user of another company too, and tell that they already have a profile.
            'user_id' => ['bail', 'required', Rule::exists('users', 'id')->where(self::usersOf($companyId)), self::notHeldByDeletedProfile(), 'unique:employee_profiles,user_id'],
            'organization_id' => ['nullable', Rule::exists('organization_units', 'id')->where(self::assignable($companyId))],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where(self::assignable($companyId))],
            'employee_number' => ['nullable', 'string', 'max:50', ...(NumberSequenceService::allowsManual('employee') ? [] : ['prohibited']), Rule::unique('employee_profiles')->where('company_id', $companyId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:active,inactive,terminated'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'skills' => 'keahlian',
            'skills.*' => 'keahlian',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'Pengguna ini sudah punya profil karyawan.',
        ];
    }

    /**
     * Users an employee profile can belong to here: homed in the company, or granted membership of it
     * (users are exempt from the company global scope, so the boundary is written out).
     */
    public static function usersOf(?int $companyId): Closure
    {
        return fn ($query) => $query->where(fn ($scope) => $scope
            ->where('users.company_id', $companyId)
            ->orWhereIn('users.id', DB::table('company_user')->where('company_id', $companyId)->select('user_id')));
    }

    /**
     * Units and vehicles an employee can be given: of the company, active and not deleted. An update also takes the one
     * the profile already holds, so a unit or vehicle deactivated since never blocks saving the rest of the profile.
     */
    public static function assignable(?int $companyId, ?int $held = null): Closure
    {
        return fn ($query) => $query->where('company_id', $companyId)->where(fn ($scope) => $scope
            ->where(fn ($live) => $live->where('is_active', true)->whereNull('deleted_at'))
            ->when($held !== null, fn ($either) => $either->orWhere('id', $held)));
    }

    /**
     * A deleted profile keeps its `user_id` (unique), so the way back to that employee is Pulihkan, not a second
     * profile. The model's company scope limits the lookup to the deleted profiles of this company.
     */
    private static function notHeldByDeletedProfile(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (EmployeeProfile::onlyTrashed()->where('user_id', $value)->exists()) {
                $fail('Pengguna ini sudah punya profil karyawan yang dihapus. Pilih Status "Dihapus" di daftar karyawan, lalu Pulihkan profil itu.');
            }
        };
    }
}
