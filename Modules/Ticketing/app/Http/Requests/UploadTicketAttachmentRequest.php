<?php

namespace Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadTicketAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.attachment.upload') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The files the apps may send too (StoreTicketAttachmentRequest): a photo or a PDF. The extension and the content both count.
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'caption' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['caption' => 'keterangan'];
    }
}
