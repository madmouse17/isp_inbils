<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

class NotificationIndexRequest extends PageRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'unread_only' => ['sometimes', 'boolean'],
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread_only');
    }
}
