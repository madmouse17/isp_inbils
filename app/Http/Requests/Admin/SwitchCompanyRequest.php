<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Company;
use Illuminate\Foundation\Http\FormRequest;

class SwitchCompanyRequest extends FormRequest
{
    /**
     * A forged, unknown or foreign company id is refused the same way: 403, with nothing revealed
     * about whether the company exists.
     */
    public function authorize(): bool
    {
        $company = $this->company();

        return $company !== null && ($this->user()?->can('switchTo', $company) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
        ];
    }

    public function company(): ?Company
    {
        $id = $this->input('company_id');

        return is_numeric($id) ? Company::query()->find((int) $id) : null;
    }
}
