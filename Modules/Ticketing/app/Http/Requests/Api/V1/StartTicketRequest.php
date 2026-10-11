<?php

namespace Modules\Ticketing\Http\Requests\Api\V1;

use App\Support\FieldCheckIn;
use Illuminate\Foundation\Http\FormRequest;

class StartTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return FieldCheckIn::rules();
    }
}
