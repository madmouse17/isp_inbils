<?php

namespace Modules\Service\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Models\ServicePackageCategory;

class SavePromoBannerRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['location_ids.*' => Location::class, 'service_package_category_ids.*' => ServicePackageCategory::class];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('announcement.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $banner = $this->route('promo_banner');

        return [
            'title' => ['required', 'string', 'max:80'],
            'alt_text' => ['required', 'string', 'max:200'],
            'image' => [
                $banner instanceof PromoBanner ? 'sometimes' : 'required',
                'file', 'max:2048', 'extensions:jpg,jpeg,png,webp', 'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                $this->bannerRatio(),
            ],
            'link_kind' => ['required', Rule::in(['none', 'app', 'url'])],
            'link_target' => ['nullable', 'string', 'max:500'],
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
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('audience') === 'targeted'
                && $this->collect('location_ids')->isEmpty()
                && $this->collect('service_package_category_ids')->isEmpty()) {
                $validator->errors()->add('audience', 'Pilih minimal satu lokasi atau kategori paket.');
            }

            $kind = $this->input('link_kind');
            $target = $this->input('link_target');
            if ($kind === 'none' && filled($target)) {
                $validator->errors()->add('link_target', 'Tujuan tautan harus kosong tanpa tautan.');
            }
            if (in_array($kind, ['app', 'url'], true) && blank($target)) {
                $validator->errors()->add('link_target', 'Tujuan tautan wajib diisi.');
            }
            if ($kind === 'app' && filled($target) && ! in_array($target, PromoBanner::APP_ROUTES, true)) {
                $validator->errors()->add('link_target', 'Rute aplikasi tidak dikenal.');
            }
            if ($kind === 'url' && filled($target) && filter_var($target, FILTER_VALIDATE_URL) === false) {
                $validator->errors()->add('link_target', 'Tautan harus berupa URL HTTPS yang valid.');
            }
            if ($kind === 'url' && filled($target) && ! str_starts_with((string) $target, 'https://')) {
                $validator->errors()->add('link_target', 'Tautan harus memakai HTTPS.');
            }
        }];
    }

    /** @return Closure(string, mixed, Closure): void */
    private function bannerRatio(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                return;
            }

            $dimensions = @getimagesize($value->getRealPath());
            if ($dimensions === false) {
                $fail('Berkas banner bukan gambar yang valid.');

                return;
            }

            [$width, $height] = $dimensions;
            if ($height === 0 || $width * 10 < $height * 19 || $width * 10 > $height * 21) {
                $fail('Rasio gambar banner harus 2:1 dengan toleransi 5%.');
            }
        };
    }
}
