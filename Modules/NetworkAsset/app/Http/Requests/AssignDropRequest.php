<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\OdnPort;

class AssignDropRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['subscription_id' => ServiceSubscription::class];
    }

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

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'subscription_id' => 'langganan',
        ];
    }
}
