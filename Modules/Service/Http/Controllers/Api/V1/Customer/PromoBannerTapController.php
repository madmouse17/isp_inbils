<?php

namespace Modules\Service\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Modules\Service\Queries\CustomerAudienceQuery;

class PromoBannerTapController extends Controller
{
    public function __invoke(Request $request, string $banner, CustomerAudienceQuery $audience): Response
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);
        $item = $audience->visibleBanners($customer)->wherePublicId($banner)->firstOrFail();
        $timezone = CompanyService::current()?->timezone ?: (string) config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $key = "promo-banner:{$item->id}:user:{$request->user()->id}:tap:{$now->toDateString()}";

        if (Cache::add($key, true, $now->endOfDay())) {
            $item->increment('tap_count');
        }

        return response()->noContent();
    }
}
