<?php

namespace App\Http\Requests\Admin;

use App\Rules\NoStackedSodConflict;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The roles a member holds in the company the user acts in, whether the member is homed here or elsewhere. An empty
 * list takes every role of this company away (`present`, not `required`).
 */
class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::authorize, not can(): a user who is no member answers 404, and only authorize() keeps that answer.
        Gate::authorize('assignRoles', $this->route('user'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', new NoStackedSodConflict($this->user())],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('company_id', CompanyService::currentId())->where('guard_name', 'web')],
        ];
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_values($this->validated('roles') ?? []);
    }
}
