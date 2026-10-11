<?php

namespace Modules\SPK\Http\Requests;

use App\Rules\IsActiveTechnician;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SPK\Models\WorkOrder;

class AssignWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wo = $this->route('wo');
        if ($wo instanceof WorkOrder && $wo->assigned_to !== null) {
            return $this->user()?->can('spk.reassign') ?? false;
        }

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
            'expected_assigned_to' => ['present', 'nullable', 'integer'],
            'expected_updated_at' => ['required', 'string', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
        ];
    }
}
