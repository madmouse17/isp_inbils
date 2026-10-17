<?php

namespace Modules\SPK\Http\Requests\Api\V1;

use App\Support\FieldCheckIn;
use Illuminate\Foundation\Http\FormRequest;
use Modules\SPK\Models\WorkOrder;

class EnRouteWorkOrderRequest extends FormRequest
{
    /**
     * The technician the SPK is assigned to, while it is assigned. Asked before the body is validated: a colleague
     * gets 403, never a 422 about the fix.
     */
    public function authorize(): bool
    {
        $workOrder = $this->route('wo');

        return $workOrder instanceof WorkOrder && ($this->user()?->can('enRoute', $workOrder) ?? false);
    }

    /** @return array<string, list<mixed>> the optional location fix, the same as when starting */
    public function rules(): array
    {
        return FieldCheckIn::rules();
    }
}
