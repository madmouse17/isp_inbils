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
            'file' => [
                'required', 'file', 'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,txt',
                'mimetypes:image/jpeg,image/png,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ];
    }
}
