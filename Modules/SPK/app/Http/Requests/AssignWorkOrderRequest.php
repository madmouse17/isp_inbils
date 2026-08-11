<?php

namespace Modules\SPK\Http\Requests;

use App\Rules\IsActiveTechnician;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.assign') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'technician_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', CompanyService::currentId()),
                new IsActiveTechnician(),
            ],
        ];
    }
}
