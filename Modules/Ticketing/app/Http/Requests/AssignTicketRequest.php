<?php

namespace Modules\Ticketing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\User;
use App\Rules\HasAnyActiveRole;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticketing\Models\Ticket;

class AssignTicketRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['handler_id' => User::class, 'expected_assigned_to' => User::class];
    }

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');
        if ($ticket instanceof Ticket && $ticket->assigned_to !== null) {
            return $this->user()?->can('ticket.reassign') ?? false;
        }

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
            'expected_assigned_to' => ['present', 'nullable', 'integer'],
            'expected_updated_at' => ['required', 'string', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
        ];
    }
}
