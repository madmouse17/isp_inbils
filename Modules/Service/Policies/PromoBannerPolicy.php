<?php

namespace Modules\Service\Policies;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\User;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Queries\CustomerAudienceQuery;

class PromoBannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('announcement.manage');
    }

    public function view(User $user, PromoBanner $banner): bool
    {
        if ($user->can('announcement.manage')) {
            return true;
        }

        $customer = EnsureCustomerAccess::customerFor($user);

        return $customer !== null
            && app(CustomerAudienceQuery::class)->visibleBanners($customer)->whereKey($banner)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('announcement.manage');
    }

    public function update(User $user, PromoBanner $banner): bool
    {
        return $user->can('announcement.manage');
    }

    public function delete(User $user, PromoBanner $banner): bool
    {
        return $user->can('announcement.manage');
    }
}
