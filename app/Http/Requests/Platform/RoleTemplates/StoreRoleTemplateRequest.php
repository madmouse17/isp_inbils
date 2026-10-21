<?php

namespace App\Http\Requests\Platform\RoleTemplates;

use App\Rules\NoSodConflict;
use App\Services\Platform\RoleTemplateService;
use App\Support\PermissionCatalogue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new role template. The key is derived from the name by the service and is taken or empty for some names, so the
 * name is refused here the way the service would refuse it. A set holding roles.manage is the administrator's
 * break-glass set and is exempt from the separation-of-duties check, as in every other role save.
 */
class StoreRoleTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'permissions' => ['array', new NoSodConflict(exempt: in_array(PermissionCatalogue::ADMINISTRATION, (array) $this->input('permissions'), true))],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions') ?? []);
    }

    /** @return list<mixed> */
    protected function nameRules(): array
    {
        return [
            'required', 'string', 'max:255',
            Rule::unique('role_templates', 'name'),
            function (string $attribute, mixed $value, Closure $fail): void {
                $key = RoleTemplateService::keyFor((string) $value);

                if ($key === '') {
                    $fail('Nama templat harus memuat huruf atau angka.');
                } elseif (RoleTemplateService::keyTaken($key)) {
                    $fail('Nama templat sudah dipakai.');
                }
            },
        ];
    }
}
