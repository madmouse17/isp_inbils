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
     * Asked before the file is validated: a ticket the caller may not reach is a 404 (a peer technician's too, the
     * policy hides it), never a 422 about the upload.
     */
    public function authorize(): bool
    {
        TicketService::assertMayAttach($this->user(), $this->ticket());

        return true;
    }

    public function ticket(): Ticket
    {
        return $this->ticket ??= TicketService::findForApp(
            (string) $this->route('id'),
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
