<?php

namespace Modules\Ticketing\Http\Requests\Api\V1;

use App\Http\Middleware\EnsureCustomerAccess;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class StoreTicketAttachmentRequest extends FormRequest
{
    private ?Ticket $ticket = null;

    /**
     * Asked before the file is validated: a ticket the caller may not reach is a 404 (a peer technician a 403),
     * never a 422 about the upload.
     */
    public function authorize(): bool
    {
        return TicketService::mayAttach($this->user(), $this->ticket());
    }

    public function ticket(): Ticket
    {
        return $this->ticket ??= TicketService::findForApp(
            (int) $this->route('id'),
            $this->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE),
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
