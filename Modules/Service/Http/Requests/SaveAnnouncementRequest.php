<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Service\Models\Announcement;

class SaveAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('announcement.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(Announcement::KINDS)],
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'location_ids' => ['sometimes', 'array', 'max:100'],
            'location_ids.*' => [
                'integer', 'distinct',
                Rule::exists('locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'service_package_category_ids' => ['sometimes', 'array', 'max:100'],
            'service_package_category_ids.*' => [
                'integer', 'distinct',
                Rule::exists('service_package_categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:starts_at'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('audience') !== 'targeted') {
                return;
            }

            if ($this->collect('location_ids')->isEmpty() && $this->collect('service_package_category_ids')->isEmpty()) {
                $validator->errors()->add('audience', 'Pilih minimal satu lokasi atau kategori paket.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'judul',
            'body' => 'isi',
            'kind' => 'jenis',
            'audience' => 'sasaran',
            'location_ids' => 'lokasi',
            'service_package_category_ids' => 'kategori paket',
            'starts_at' => 'mulai',
            'ends_at' => 'selesai',
        ];
    }
}
