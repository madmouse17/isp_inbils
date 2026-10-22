<?php

namespace App\Http\Requests\Platform\Audit;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The query string of the audit page. It is a read-only page, so a filter that cannot be used never refuses the visit:
 * the page opens on what was valid and says next to each filter what was not (F11-D09).
 */
class AuditIndexRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** `platform` is the operator's own log, one row per action; `support` is the copy a tenant sees; `all` lists both. */
    public const SOURCES = ['platform', 'support', 'all'];

    public const PER_PAGE = [10, 25, 50];

    /** @var array<string, string> */
    private array $filterErrors = [];

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['company_id' => Company::class, 'platform_admin_id' => PlatformAdmin::class];
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'company_id' => [
                'nullable',
                'integer',
                Rule::exists((new Company())->getTable(), 'id')->whereNull('parent_company_id'),
            ],
            'platform_admin_id' => ['nullable', 'integer', Rule::exists((new PlatformAdmin())->getTable(), 'id')],
            'event' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in(self::PER_PAGE)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'company_id.exists' => 'Perusahaan tidak ditemukan.',
            'company_id.integer' => 'Perusahaan tidak ditemukan.',
            'platform_admin_id.exists' => 'Operator tidak ditemukan.',
            'platform_admin_id.integer' => 'Operator tidak ditemukan.',
            'event.max' => 'Kegiatan paling banyak 100 karakter.',
            'source.in' => 'Sumber tidak dikenal. Pilih Platform, Akses dukungan, atau Semua sumber.',
            'date_from.date_format' => 'Tanggal mulai tidak sesuai. Pakai format tahun-bulan-tanggal, misalnya 2026-10-20.',
            'date_to.date_format' => 'Tanggal akhir tidak sesuai. Pakai format tahun-bulan-tanggal, misalnya 2026-10-20.',
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
            'search.max' => 'Kata kunci paling banyak 100 karakter.',
            'page.integer' => 'Nomor halaman harus bilangan bulat mulai dari 1.',
            'page.min' => 'Nomor halaman harus bilangan bulat mulai dari 1.',
            'per_page.in' => 'Pilih 10, 25, atau 50 baris per halaman.',
        ];
    }

    /**
     * The usable part of the filters, with a default for each one that was refused. The answer to a refused one is
     * filterErrors(), not a redirect.
     *
     * @return array{company_id: ?int, platform_admin_id: ?int, event: ?string, source: string, date_from: ?string, date_to: ?string, search: ?string, page: int, per_page: int}
     */
    public function filters(): array
    {
        $valid = Arr::except($this->input(), array_keys($this->filterErrors));

        return [
            'company_id' => isset($valid['company_id']) ? (int) $valid['company_id'] : null,
            'platform_admin_id' => isset($valid['platform_admin_id']) ? (int) $valid['platform_admin_id'] : null,
            'event' => isset($valid['event']) ? trim($valid['event']) ?: null : null,
            'source' => $valid['source'] ?? self::SOURCES[0],
            'date_from' => $valid['date_from'] ?? null,
            'date_to' => $valid['date_to'] ?? null,
            'search' => isset($valid['search']) ? trim($valid['search']) ?: null : null,
            'page' => (int) ($valid['page'] ?? 1),
            'per_page' => (int) ($valid['per_page'] ?? self::PER_PAGE[0]),
        ];
    }

    /** What was wrong with each refused filter, by filter name. @return array<string, string> */
    public function filterErrors(): array
    {
        return $this->filterErrors;
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        foreach ($validator->errors()->messages() as $filter => $messages) {
            $this->filterErrors[$filter] = $messages[0];
        }
    }
}
