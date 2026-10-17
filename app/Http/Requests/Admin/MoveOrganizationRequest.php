<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\OrganizationUnit;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveOrganizationRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['parent_id' => OrganizationUnit::class];
    }

    public function authorize(): bool
    {
        $unit = $this->route('organization_unit');

        return $unit instanceof OrganizationUnit && ($this->user()?->can('update', $unit) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', Rule::exists('organization_units', 'id')->where('company_id', CompanyService::currentId())],
        ];
    }
}
