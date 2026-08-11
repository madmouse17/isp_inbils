<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'code' => ['required', 'string', 'max:50', Rule::unique('customers')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Individual,Company'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'tax_id' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:100'],
            'contact_person' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:255'],
            'area_coverage_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'addresses' => ['required', 'array', 'min:1'],
            'addresses.*' => ['required', 'array:label,address,city,postal_code,is_installation_point,is_primary,notes'],
            'addresses.*.label' => ['required', 'string', 'max:100'],
            'addresses.*.address' => ['required', 'string'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
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
            'subscription' => ['required', 'array:service_package_id,serving_pop_id,billing_day,mrc_amount,otc_installation_fee,contract_months,notes'],
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
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $addresses = collect($this->input('addresses', []));
            $contacts = collect($this->input('contacts', []));

            if ($addresses->filter(fn (mixed $address): bool => is_array($address)
                && filter_var($address['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('addresses', 'Select exactly one primary address.');
            }

            if ($addresses->filter(fn (mixed $address): bool => is_array($address)
                && filter_var($address['is_installation_point'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('addresses', 'Select exactly one installation address.');
            }

            if ($contacts->filter(fn (mixed $contact): bool => is_array($contact)
                && filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() !== 1) {
                $validator->errors()->add('contacts', 'Select exactly one primary contact.');
            }
        }];
    }
}
