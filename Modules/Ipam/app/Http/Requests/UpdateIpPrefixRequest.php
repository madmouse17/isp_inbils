<?php

namespace Modules\Ipam\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;

/** The cidr is not a field here: it is immutable, so a submitted value never reaches validated(). */
class UpdateIpPrefixRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['location_id' => Location::class, 'vlan_id' => Vlan::class];
    }

    public function authorize(): bool
    {
        $prefix = $this->route('prefix');

        return $prefix instanceof IpPrefix && ($this->user()?->can('update', $prefix) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'usage' => ['required', 'string', Rule::in(IpPrefix::USAGES)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'vlan_id' => ['nullable', 'integer', Rule::exists('vlans', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'usage' => 'peruntukan',
            'location_id' => 'POP',
            'vlan_id' => 'VLAN',
            'description' => 'keterangan',
        ];
    }
}
