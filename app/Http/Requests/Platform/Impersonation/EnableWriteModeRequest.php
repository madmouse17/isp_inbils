<?php

namespace App\Http\Requests\Platform\Impersonation;

use App\Services\Platform\ImpersonationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class EnableWriteModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user('platform') === null) {
            return false;
        }

        if (app(ImpersonationService::class)->current($this->session()) === null) {
            throw new ConflictHttpException('Tidak ada akses dukungan yang aktif.');
        }

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyCode = app(ImpersonationService::class)->current($this->session())?->company->code;

        return ['confirmation_code' => ['required', 'string', Rule::in(array_filter([$companyCode]))]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirmation_code.in' => 'Kode perusahaan yang diketik tidak sesuai.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['confirmation_code' => 'kode konfirmasi'];
    }
}
