<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Customer;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Rules\IsEligibleCustomerAcquirer;
use App\Services\Core\CompanyService;
use App\Services\Core\IndonesiaRegionService;
use App\Services\Core\NumberSequenceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customer.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'code' => ['nullable', 'string', 'max:50', ...(NumberSequenceService::allowsManual('customer') ? [] : ['prohibited']), Rule::unique('customers')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Individual,Company'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'tax_id' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:100'],
            'contact_person' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:255'],
            'area_coverage_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'addresses' => ['required', 'array', 'min:1'],
            'addresses.*' => ['required', 'array:label,address,city,postal_code,province_code,city_code,district_code,village_code,lat,lng,is_installation_point,is_primary,notes'],
            'addresses.*.label' => ['required', 'string', 'max:100'],
            'addresses.*.address' => ['required', 'string'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.province_code' => ['required', Rule::exists((new Province())->getTable(), 'code')],
            'addresses.*.city_code' => ['required', Rule::exists((new City())->getTable(), 'code')],
            'addresses.*.district_code' => ['required', Rule::exists((new District())->getTable(), 'code')],
            'addresses.*.village_code' => ['required', Rule::exists((new Village())->getTable(), 'code')],
            'addresses.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'addresses.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'addresses.*.is_installation_point' => ['required', 'boolean'],
            'addresses.*.is_primary' => ['required', 'boolean'],
            'addresses.*.notes' => ['nullable', 'string'],
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*' => ['required', 'array:name,position,phone,email,is_primary,notes'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['required', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'contacts.*.email' => ['required', 'email', 'max:255'],
            'contacts.*.is_primary' => ['required', 'boolean'],
            'contacts.*.notes' => ['nullable', 'string'],
            'subscription' => ['required', 'array:service_package_id,serving_pop_id,billing_day,mrc_amount,otc_installation_fee,contract_months,notes,odn_port_id'],
            'subscription.service_package_id' => [
                'required',
                Rule::exists('service_packages', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'subscription.serving_pop_id' => [
                'required',
                Rule::exists('locations', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'subscription.billing_day' => ['required', 'integer', 'between:1,28'],
            'subscription.mrc_amount' => ['nullable', 'numeric', 'min:0'],
            'subscription.otc_installation_fee' => ['nullable', 'numeric', 'min:0'],
            'subscription.contract_months' => ['nullable', 'integer', 'min:1'],
            'subscription.notes' => ['nullable', 'string'],
            ...$this->acquirerRules(),
            ...$this->dropPortRules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['type' => 'tipe pelanggan'];
    }

    /**
     * The acquirer key is validated only for holders of customer.sales.assign; for everyone else it is
     * never validated and never reaches validated(), so a forged value is ignored and the server
     * default applies.
     *
     * @return array<string, array<int, mixed>>
     */
    private function acquirerRules(): array
    {
        if (! ($this->user()?->can('assignAcquirer', Customer::class) ?? false)) {
            return [];
        }

        return ['acquired_by_user_id' => ['nullable', 'bail', CustomerAcquirerQuery::idRule(), new IsEligibleCustomerAcquirer()]];
    }

    /**
     * The drop port is validated only for holders of odn.assign; for everyone else the key never reaches
     * validated(), so a forged value is ignored. Whether the port is free and under the serving POP is
     * decided by OdnService inside the onboarding transaction.
     *
     * @return array<string, array<int, mixed>>
     */
    private function dropPortRules(): array
    {
        if (! ($this->user()?->can('odn.assign') ?? false)) {
            return [];
        }

        return ['subscription.odn_port_id' => ['nullable', 'integer', Rule::exists('odn_ports', 'id')->where('company_id', CompanyService::currentId())]];
    }

    /**
     * A drop port sent by a user who cannot assign one is removed before validation (the parent `subscription`
     * rule would otherwise carry the whole array, forged key included, into validated()).
     */
    protected function prepareForValidation(): void
    {
        $subscription = $this->input('subscription');

        if (is_array($subscription) && ! ($this->user()?->can('odn.assign') ?? false)) {
            $this->merge(['subscription' => Arr::except($subscription, 'odn_port_id')]);
        }
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $addresses = collect($this->input('addresses', []));
            $contacts = collect($this->input('contacts', []));

            if ($addresses->filter(fn (mixed $address): bool => is_array($address)
                && filter_var($address['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('addresses', 'Pilih tepat satu alamat utama.');
            }

            if ($addresses->filter(fn (mixed $address): bool => is_array($address)
                && filter_var($address['is_installation_point'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('addresses', 'Pilih tepat satu alamat pemasangan.');
            }

            if ($contacts->filter(fn (mixed $contact): bool => is_array($contact)
                && filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('contacts', 'Pilih tepat satu kontak utama.');
            }

            $addresses->each(function (mixed $address, int $index) use ($validator): void {
                if (! is_array($address)) {
                    return;
                }

                $codes = collect(['province_code', 'city_code', 'district_code', 'village_code'])
                    ->map(fn (string $key): mixed => $address[$key] ?? null);

                if ($codes->every(fn (mixed $code): bool => is_string($code))
                    && ! IndonesiaRegionService::hierarchyExists(...$codes->all())) {
                    $validator->errors()->add("addresses.{$index}.village_code", 'Provinsi, kota/kabupaten, kecamatan, dan kelurahan/desa tidak saling cocok.');
                }
            });
        }];
    }
}
