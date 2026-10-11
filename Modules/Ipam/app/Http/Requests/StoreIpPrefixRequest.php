<?php

namespace Modules\Ipam\Http\Requests;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Support\IpNotation;

class StoreIpPrefixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', IpPrefix::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'cidr' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || IpNotation::prefix($value) === null) {
                    $fail('Enter a valid IPv4 or IPv6 prefix in CIDR form, for example 10.20.0.0/22.');
                }
            }],
            'usage' => ['required', 'string', Rule::in(IpPrefix::USAGES)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'vlan_id' => ['nullable', 'integer', Rule::exists('vlans', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
