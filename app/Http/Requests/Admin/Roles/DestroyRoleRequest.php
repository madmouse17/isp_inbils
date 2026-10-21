<?php

namespace App\Http\Requests\Admin\Roles;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Deletes a role. A role that still has users needs `move_to`, the role that takes them over; CompanyRoleService
 * asks for it under the company lock, where the users are counted. The id is resolved among the roles of the
 * company the user acts in, so another company's role is refused like an unknown one.
 */
class DestroyRoleRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string<Role>> */
    protected function publicIdFields(): array
    {
        return ['move_to' => Role::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('role')) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['move_to' => ['nullable', 'integer']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['move_to' => 'peran tujuan'];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Role $role */
            $role = $this->route('role');

            if ((int) $this->input('move_to') === $role->id) {
                $validator->errors()->add('move_to', 'Pilih peran lain sebagai tujuan.');
            }
        }];
    }

    /** The role that takes over the users; null when none was asked for. */
    public function moveTo(): ?Role
    {
        $id = $this->validated('move_to');

        return $id === null ? null : Role::query()->find($id);
    }
}
