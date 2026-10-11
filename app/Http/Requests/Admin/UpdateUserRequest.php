<?php

namespace App\Http\Requests\Admin;

use App\Rules\NoStackedSodConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['array', new NoStackedSodConflict($this->user())],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $roles = (array) $this->input('roles', []);
            if (in_array('admin', $roles, true) && ! $this->user()?->hasRole('admin')) {
                $validator->errors()->add('roles', 'Only an administrator can assign the admin role.');
            }
        });
    }
}
