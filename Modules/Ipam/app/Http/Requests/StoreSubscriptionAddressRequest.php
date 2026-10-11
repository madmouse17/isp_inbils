<?php

namespace Modules\Ipam\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionAddressRequest extends FormRequest
{
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
}
