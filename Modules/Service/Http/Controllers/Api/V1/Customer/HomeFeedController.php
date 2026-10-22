<?php

namespace Modules\Service\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Queries\CustomerAudienceQuery;
use Modules\Service\Resources\Api\V1\HomeFeedResource;
use Modules\Service\Services\HomeFeedCache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, CustomerAudienceQuery $audience): HomeFeedResource
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        // Attribute rows, not models: the cache store refuses every PHP object (config/cache.php, serializable_classes).
        $feed = Cache::remember(HomeFeedCache::key((int) $customer->company_id, (int) $customer->id), 60, fn (): array => [
            'announcements' => $audience->visibleAnnouncements($customer)
                ->orderByRaw("CASE WHEN kind IN ('outage', 'maintenance') THEN 0 ELSE 1 END")
                ->latest('published_at')
                ->limit(20)
                ->get()
                ->map->getAttributes()
                ->all(),
            'banners' => $audience->visibleBanners($customer)
                ->with('media')
                ->orderBy('sort_order')
                ->latest('published_at')
                ->limit(5)
                ->get()
                ->map(fn (PromoBanner $banner): array => ['row' => $banner->getAttributes(), 'media' => $banner->media->map->getAttributes()->all()])
                ->all(),
        ]);

        return new HomeFeedResource([
            'announcements' => Announcement::hydrate($feed['announcements']),
            'banners' => PromoBanner::hydrate(array_column($feed['banners'], 'row'))
                ->each(fn (PromoBanner $banner, int $position) => $banner->setRelation('media', Media::hydrate($feed['banners'][$position]['media']))),
        ]);
    }
}
