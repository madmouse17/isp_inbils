<?php

namespace Modules\Service\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Service\Models\PromoBanner;

class PromoBannerService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): PromoBanner
    {
        return DB::transaction(function () use ($data): PromoBanner {
            $banner = PromoBanner::query()->create($this->attributes($data));
            $this->syncTargets($banner, $data);
            $this->storeImage($banner, $data['image'] ?? null);
            AuditService::log('announcement', 'created', ['promo_banner_id' => $banner->id], $banner);
            HomeFeedCache::flush((int) $banner->company_id);

            return $banner;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PromoBanner $banner, array $data): PromoBanner
    {
        return DB::transaction(function () use ($banner, $data): PromoBanner {
            $banner->update($this->attributes($data));
            $this->syncTargets($banner, $data);
            $this->storeImage($banner, $data['image'] ?? null);
            AuditService::log('announcement', 'updated', ['promo_banner_id' => $banner->id], $banner);
            HomeFeedCache::flush((int) $banner->company_id);

            return $banner;
        });
    }

    public function publish(PromoBanner $banner): PromoBanner
    {
        if ($banner->published_at !== null) {
            throw ValidationException::withMessages(['status' => 'Banner sudah diterbitkan.']);
        }
        if ($banner->getFirstMedia('promo_banner') === null) {
            throw ValidationException::withMessages(['image' => 'Gambar banner wajib tersedia.']);
        }

        $banner->forceFill(['published_at' => now(), 'is_active' => true])->save();
        AuditService::log('announcement', 'published', ['promo_banner_id' => $banner->id], $banner);
        HomeFeedCache::flush((int) $banner->company_id);

        return $banner;
    }

    public function deactivate(PromoBanner $banner): PromoBanner
    {
        abort_if($banner->published_at === null, 422, 'Draf tidak perlu dinonaktifkan.');
        $banner->forceFill(['is_active' => false])->save();
        AuditService::log('announcement', 'deactivated', ['promo_banner_id' => $banner->id], $banner);
        HomeFeedCache::flush((int) $banner->company_id);

        return $banner;
    }

    public function deleteDraft(PromoBanner $banner): void
    {
        if ($banner->published_at !== null) {
            throw ValidationException::withMessages(['status' => 'Banner terbit hanya dapat dinonaktifkan.']);
        }

        $companyId = (int) $banner->company_id;
        AuditService::log('announcement', 'deleted', ['promo_banner_id' => $banner->id], $banner);
        $banner->delete();
        HomeFeedCache::flush($companyId);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $timezone = CompanyService::current()?->timezone ?: (string) config('app.timezone');
        $storageTimezone = (string) config('app.timezone');
        $attributes = Arr::only($data, ['title', 'alt_text', 'link_kind', 'link_target', 'audience', 'sort_order']);
        if (($attributes['link_kind'] ?? null) === 'none') {
            $attributes['link_target'] = null;
        }
        $attributes['starts_at'] = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $data['starts_at'], $timezone)->setTimezone($storageTimezone);
        $attributes['ends_at'] = filled($data['ends_at'] ?? null)
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $data['ends_at'], $timezone)->setTimezone($storageTimezone)
            : null;

        return $attributes;
    }

    /** @param array<string, mixed> $data */
    private function syncTargets(PromoBanner $banner, array $data): void
    {
        $banner->locations()->sync($banner->audience === 'targeted' ? ($data['location_ids'] ?? []) : []);
        $banner->servicePackageCategories()->sync($banner->audience === 'targeted' ? ($data['service_package_category_ids'] ?? []) : []);
    }

    /** Stores the new image first and removes the old one only after the commit; callers run this inside their transaction. */
    private function storeImage(PromoBanner $banner, mixed $image): void
    {
        if (! $image instanceof UploadedFile) {
            return;
        }

        PromoBanner::query()->whereKey($banner->id)->lockForUpdate()->firstOrFail();
        $old = $banner->media()->where('collection_name', 'promo_banner')->get();

        $new = $banner->addMedia($image)
            ->withCustomProperties(['company_id' => (int) $banner->company_id])
            ->toMediaCollection('promo_banner');

        DB::afterRollBack(fn () => $new->delete());
        DB::afterCommit(fn () => $old->each->delete());
    }
}
