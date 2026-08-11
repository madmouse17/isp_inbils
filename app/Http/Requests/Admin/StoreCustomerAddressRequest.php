<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\IndonesiaRegionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;

class StoreCustomerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customer.address.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'province_code' => ['required', Rule::exists((new Province())->getTable(), 'code')],
            'city_code' => ['required', Rule::exists((new City())->getTable(), 'code')],
            'district_code' => ['required', Rule::exists((new District())->getTable(), 'code')],
            'village_code' => ['required', Rule::exists((new Village())->getTable(), 'code')],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'is_installation_point' => ['boolean'],
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $codes = collect(['province_code', 'city_code', 'district_code', 'village_code'])
                ->map(fn (string $key): mixed => $this->input($key));

            if ($codes->every(fn (mixed $code): bool => is_string($code))
                && ! IndonesiaRegionService::hierarchyExists(...$codes->all())) {
                $validator->errors()->add('village_code', 'The selected region hierarchy is invalid.');
            }
        }];
    }
}
