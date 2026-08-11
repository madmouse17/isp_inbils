<?php

namespace Modules\Ticketing\Http\Requests;

use App\Rules\HasAnyActiveRole;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.assign') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'handler_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $companyId),
                new HasAnyActiveRole(['admin', 'manager', 'noc', 'staff', 'technician']),
            ],
        ];
    }
}
