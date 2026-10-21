<?php

namespace App\Http\Requests\Admin;

use App\Http\Middleware\EnforceCompanyTwoFactor;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use App\Support\ServiceHours;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanySettingsRequest extends FormRequest
{
    /** Stamped by the controller on the first save of the form (F1-U05); a client never sets or clears it. */
    public const BILLING_CONFIRMED_AT = 'billing_defaults_confirmed_at';

    public function authorize(): bool
    {
        return $this->user()?->can('company.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (is_array($settings) && array_key_exists(self::BILLING_CONFIRMED_AT, $settings)) {
            unset($settings[self::BILLING_CONFIRMED_AT]);
            $this->merge(['settings' => $settings]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
            'settings.auto_send_installation_invoice' => ['sometimes', 'required', 'boolean'],
            'settings.bill_installation_materials' => ['sometimes', 'required', 'boolean'],
            // What every invoice line is taxed at (tax_rate holds two decimals) and the days from the issue date to the due date.
            'settings.tax_ppn_rate' => ['numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'settings.invoice_due_days' => ['integer', 'min:0', 'max:365'],
            'settings.auto_suspend_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.auto_suspend_overdue_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:60'],
            'settings.auto_reactivate_on_payment' => ['sometimes', 'required', 'boolean'],
            'settings.whatsapp_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.whatsapp_events' => ['sometimes', 'array'],
            'settings.whatsapp_events.*' => ['string', Rule::in(array_keys(config('whatsapp.events')))],
            'settings.whatsapp_quiet_start' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.whatsapp_quiet_end' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.whatsapp_monthly_cap' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'settings.map_city_code' => ['sometimes', 'nullable', 'string', 'exists:indonesia_cities,code'],
            // Indonesia's bounding box; lat and lng are only meaningful as a pair.
            'settings.map_lat' => ['nullable', 'required_with:settings.map_lng', 'numeric', 'between:-11,6'],
            'settings.map_lng' => ['nullable', 'required_with:settings.map_lat', 'numeric', 'between:94,142'],
            'settings.map_zoom' => ['sometimes', 'nullable', 'integer', 'between:3,18'],
            // The farthest a technician may check in from the customer's address without giving a reason (K10).
            'settings.checkin_max_distance_m' => ['sometimes', 'required', 'integer', 'min:50', 'max:100000'],
            // The open hours of the company, a day at a time: null is a closed day (K11). They travel in their own field so
            // this structure is always checked; the days themselves are checked in after(), because a wildcard rule would
            // turn the null of a closed day into an empty window and refuse it.
            'settings.service_hours' => ['prohibited'],
            'service_hours' => ['sometimes', 'nullable', 'array:'.implode(',', ServiceHours::DAYS)],
            'settings.'.EnforceCompanyTwoFactor::SETTING => ['bail', 'sometimes', 'required', 'boolean', $this->ownTwoFactorBeforeRequiring(...)],
            OptimisticLock::FIELD => OptimisticLock::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'settings.tax_ppn_rate.numeric' => 'Tarif PPN harus berupa angka.',
            'settings.tax_ppn_rate.decimal' => 'Tarif PPN paling banyak dua angka di belakang koma.',
            'settings.tax_ppn_rate.min' => 'Tarif PPN tidak boleh negatif.',
            'settings.tax_ppn_rate.max' => 'Tarif PPN paling tinggi 100%.',
            'settings.invoice_due_days.integer' => 'Jatuh tempo harus berupa bilangan bulat, dalam hari.',
            'settings.invoice_due_days.min' => 'Jatuh tempo tidak boleh negatif.',
            'settings.invoice_due_days.max' => 'Jatuh tempo paling lama 365 hari.',
            'settings.map_lat.between' => 'Lintang harus di antara -11 dan 6.',
            'settings.map_lng.between' => 'Bujur harus di antara 94 dan 142.',
            'settings.map_zoom.between' => 'Zoom harus di antara 3 dan 18.',
            'settings.checkin_max_distance_m.required' => 'Jarak check-in wajib diisi.',
            'settings.checkin_max_distance_m.integer' => 'Jarak check-in harus berupa bilangan bulat, dalam meter.',
            'settings.checkin_max_distance_m.min' => 'Jarak check-in paling dekat 50 meter.',
            'settings.checkin_max_distance_m.max' => 'Jarak check-in paling jauh 100.000 meter.',
            'service_hours.array' => 'Jam layanan harus berupa jadwal per hari, Senin sampai Minggu.',
        ];
    }

    /**
     * Each open day holds an opening and a closing time, `HH:MM`, and closes after it opens: the hours never run past midnight.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $week = $this->input('service_hours');

            foreach (is_array($week) ? ServiceHours::DAYS : [] as $day) {
                $hours = $week[$day] ?? null;

                if ($hours === null) {
                    continue;
                }

                if (! is_array($hours) || ! array_is_list($hours) || count($hours) !== 2) {
                    $validator->errors()->add("service_hours.{$day}", 'Jam layanan satu hari berisi jam buka dan jam tutup.');

                    continue;
                }

                foreach (['Jam buka', 'Jam tutup'] as $index => $label) {
                    $time = $hours[$index];

                    if ($time === null || $time === '') {
                        $validator->errors()->add("service_hours.{$day}.{$index}", "{$label} wajib diisi.");
                    } elseif (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                        $validator->errors()->add("service_hours.{$day}.{$index}", 'Format jam tidak valid. Contoh: 08:30.');
                    }
                }

                if (! $validator->errors()->hasAny(["service_hours.{$day}.0", "service_hours.{$day}.1"]) && $hours[0] >= $hours[1]) {
                    $validator->errors()->add("service_hours.{$day}.1", 'Jam tutup harus setelah jam buka.');
                }
            }
        }];
    }

    /** Turning the policy on locks out every admin who has no second factor yet, so whoever turns it on must hold one. */
    private function ownTwoFactorBeforeRequiring(string $attribute, mixed $value, Closure $fail): void
    {
        $turnsOn = filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! EnforceCompanyTwoFactor::required(CompanyService::current());

        if ($turnsOn && ! $this->user()?->hasTwoFactorEnabled()) {
            $fail('Aktifkan 2FA di profil Anda dulu sebelum mewajibkannya.');
        }
    }
}
