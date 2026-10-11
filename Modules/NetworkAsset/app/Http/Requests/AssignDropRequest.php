<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\OdnPort;

class AssignDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        $port = $this->route('port');

        return $port instanceof OdnPort && ($this->user()?->can('assignDrop', $port) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subscription_id' => ['required', 'integer', Rule::exists('service_subscriptions', 'id')->where('company_id', CompanyService::currentId())->whereNull('deleted_at')],
        ];
    }
}
