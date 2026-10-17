<?php

namespace Modules\Service\Console;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Modules\Service\Models\PromoBanner;

class CleanupPromoBannerImages extends Command
{
    protected $signature = 'promo-banners:cleanup-images';

    protected $description = 'Delete images of promo banners ended more than 180 days ago';

    public function handle(): int
    {
        PromoBanner::withoutCompany()
            ->where('ends_at', '<', now()->subDays(180))
            ->whereHas('media', fn ($query) => $query->where('collection_name', 'promo_banner'))
            ->orderBy('id')
            ->chunkById(100, function ($banners): void {
                foreach ($banners as $banner) {
                    CompanyService::runAs((int) $banner->company_id, function () use ($banner): void {
                        $mediaId = $banner->getFirstMedia('promo_banner')?->id;
                        $banner->clearMediaCollection('promo_banner');
                        AuditService::log('announcement', 'image_purged', [
                            'promo_banner_id' => $banner->id,
                            'media_id' => $mediaId,
                        ], $banner);
                    });
                }
            });

        return self::SUCCESS;
    }
}
