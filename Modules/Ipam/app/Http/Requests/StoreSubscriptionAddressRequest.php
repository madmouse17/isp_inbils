<?php

namespace Modules\Ipam\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ipam\Models\IpPrefix;

class StoreSubscriptionAddressRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['ip_prefix_id' => IpPrefix::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('ipam.assign') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_prefix_id' => ['required', 'integer', Rule::exists('ip_prefixes', 'id')->where('company_id', CompanyService::currentId())],
            // Null (or absent) asks for the lowest free IPv4 address of the pool.
            'address' => ['nullable', 'string', 'max:45'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ip_prefix_id' => 'pool',
            'address' => 'alamat',
        ];
    }
}
