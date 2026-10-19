<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\CompanyIntegration;
use App\Services\Core\TwoFactorService;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Validator;

/**
 * Saves one provider card of Pengaturan → Integrasi. The rules follow the provider of the URL. Every save needs a
 * fresh authenticator code (TwoFactorService::verifyChallenge spends it, so a replay fails), checked last: a mistake in
 * a field does not burn the code. A secret left blank keeps the stored one, so an enabled card must hold every
 * required secret, stored or submitted.
 */
class UpdateCompanyIntegrationRequest extends FormRequest
{
    /** Secrets an enabled integration cannot run without; the mail and RADIUS passwords may be empty. */
    private const REQUIRED_SECRETS = [
        'midtrans' => ['server_key'],
        'whatsapp' => ['access_token', 'app_secret', 'webhook_verify_token'],
    ];

    private ?CompanyIntegration $stored = null;

    private bool $storedLoaded = false;

    public function authorize(): bool
    {
        return $this->user()?->can('company.integrations.manage') ?? false;
    }

    public function provider(): string
    {
        return (string) $this->route('provider');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'is_enabled' => ['required', 'boolean'],
            ...match ($this->provider()) {
                'midtrans' => [
                    'server_key' => ['nullable', 'string', 'max:255', 'regex:/^(SB-)?Mid-server-/'],
                    'is_production' => ['required', 'boolean'],
                ],
                'whatsapp' => [
                    'phone_number_id' => [$this->whenEnabled(), 'nullable', 'digits_between:5,30'],
                    'access_token' => ['nullable', 'string', 'max:1024'],
                    'app_secret' => ['nullable', 'string', 'max:255'],
                    'webhook_verify_token' => ['nullable', 'string', 'min:16', 'max:255'],
                ],
                'mail' => [
                    'host' => [$this->whenEnabled(), 'nullable', 'string', 'max:255', $this->allowedHost()],
                    'port' => ['nullable', 'integer', 'between:1,65535'],
                    'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
                    'username' => ['nullable', 'string', 'max:255'],
                    'password' => ['nullable', 'string', 'max:255'],
                    'from_address' => [$this->whenEnabled(), 'nullable', 'email:rfc', 'max:255'],
                    'from_name' => ['nullable', 'string', 'max:100'],
                ],
                'radius' => [
                    'host' => [$this->whenEnabled(), 'nullable', 'string', 'max:255', $this->allowedHost()],
                    'port' => ['nullable', 'integer', 'between:1,65535'],
                    'database' => [$this->whenEnabled(), 'nullable', 'regex:/^[A-Za-z0-9_]+$/', 'max:64'],
                    'username' => [$this->whenEnabled(), 'nullable', 'string', 'max:64'],
                    'password' => ['nullable', 'string', 'max:255'],
                ],
                default => [],
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode autentikasi',
            'is_enabled' => 'status aktif',
            'server_key' => 'server key',
            'is_production' => 'mode',
            'phone_number_id' => 'ID nomor',
            'access_token' => 'access token',
            'app_secret' => 'app secret',
            'webhook_verify_token' => 'verify token webhook',
            'host' => 'host',
            'port' => 'port',
            'scheme' => 'enkripsi',
            'username' => 'nama pengguna',
            'password' => 'kata sandi',
            'from_address' => 'alamat pengirim',
            'from_name' => 'nama pengirim',
            'database' => 'nama database',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkRequiredSecrets($validator),
            fn (Validator $validator) => $this->checkMidtransMode($validator),
            fn (Validator $validator) => $this->checkAuthenticatorCode($validator),
        ];
    }

    /**
     * The error handler flashes the original request's input back into the session after a refusal, and this request is
     * only a copy of it. A secret or an authenticator code must not be written to the session store, so they leave
     * the original first.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $original = app('request');

        foreach ([...Arr::flatten(CompanyIntegration::SECRETS), 'code'] as $key) {
            $original->request->remove($key);
            $original->json()->remove($key);
        }

        parent::failedValidation($validator);
    }

    private function whenEnabled(): RequiredIf
    {
        return Rule::requiredIf(fn (): bool => $this->boolean('is_enabled'));
    }

    private function allowedHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! CompanyIntegration::isAllowedHost($value)) {
                $fail('Host tidak diizinkan: alamat lokal, alamat metadata, atau nama host yang tidak valid.');
            }
        };
    }

    private function stored(): ?CompanyIntegration
    {
        if (! $this->storedLoaded) {
            $this->stored = CompanyIntegration::query()->where('provider', $this->provider())->first();
            $this->storedLoaded = true;
        }

        return $this->stored;
    }

    private function checkRequiredSecrets(Validator $validator): void
    {
        if (! $this->boolean('is_enabled')) {
            return;
        }

        foreach (self::REQUIRED_SECRETS[$this->provider()] ?? [] as $key) {
            if (! filled($this->input($key)) && $this->stored()?->secret($key) === null) {
                $validator->errors()->add($key, 'Wajib diisi untuk mengaktifkan integrasi ini.');
            }
        }
    }

    /** A sandbox key (SB-Mid-server-) only works in sandbox mode and a production key only in production mode. */
    private function checkMidtransMode(Validator $validator): void
    {
        if ($this->provider() !== 'midtrans' || $validator->errors()->hasAny(['server_key', 'is_production'])) {
            return;
        }

        $submitted = filled($this->input('server_key'));
        $key = $submitted ? (string) $this->input('server_key') : $this->stored()?->secret('server_key');

        if ($key === null) {
            return;
        }

        $sandboxKey = str_starts_with($key, 'SB-');
        $production = $this->boolean('is_production');

        if ($sandboxKey && $production) {
            $validator->errors()->add($submitted ? 'server_key' : 'is_production', 'Server key sandbox (SB-Mid-server-) tidak bisa dipakai di mode produksi.');
        } elseif (! $sandboxKey && ! $production) {
            $validator->errors()->add($submitted ? 'server_key' : 'is_production', 'Server key produksi (Mid-server-) tidak bisa dipakai di mode sandbox.');
        }
    }

    private function checkAuthenticatorCode(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $user = $this->user();

        if (! $user->hasTwoFactorEnabled()) {
            $validator->errors()->add('code', 'Aktifkan autentikasi dua langkah di Profil sebelum menyimpan integrasi.');
        } elseif (! app(TwoFactorService::class)->verifyChallenge($user, (string) $this->input('code'))) {
            $validator->errors()->add('code', 'Kode autentikasi salah atau sudah dipakai.');
        }
    }
}
