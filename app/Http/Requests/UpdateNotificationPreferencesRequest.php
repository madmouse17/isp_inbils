<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The person's own switches for the mail they get. Whose they are comes from the session, never from the request. */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The daily warehouse digest of requests and returns waiting for the person (`inventory:send-digest`).
            'inventory_digest' => ['required', 'boolean'],
        ];
    }
}
