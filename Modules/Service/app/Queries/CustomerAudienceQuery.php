<?php

namespace Modules\Service\Queries;

use App\Models\Core\Customer;
use App\Models\Core\Customer as CustomerModel;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;

class CustomerAudienceQuery
{
    /** @return Builder<Announcement> */
    public function visibleAnnouncements(Customer $customer): Builder
    {
        return $this->applyAnnouncementAudience(
            Announcement::query()->publishedAndLive(),
            (int) $customer->id,
        );
    }

    /** @return Builder<PromoBanner> */
    public function visibleBanners(Customer $customer): Builder
    {
        return $this->applyPromoBannerAudience(
            PromoBanner::query()->publishedAndLive(),
            (int) $customer->id,
        );
    }

    /** @param Builder<PromoBanner> $query
     * @return Builder<PromoBanner>
     */
    public function applyPromoBannerAudience(Builder $query, int $customerId): Builder
    {
        return $query->where(function (Builder $audience) use ($customerId): void {
            $audience->where('audience', 'all')
                ->orWhere(function (Builder $targeted) use ($customerId): void {
                    $targeted->where('audience', 'targeted')
                        ->where(function (Builder $hasTarget): void {
                            $hasTarget->whereExists(fn (QueryBuilder $locations) => $locations
                                ->selectRaw('1')
                                ->from('location_promo_banner as audience_location')
                                ->whereColumn('audience_location.promo_banner_id', 'promo_banners.id'))
                                ->orWhereExists(fn (QueryBuilder $categories) => $categories
                                    ->selectRaw('1')
                                    ->from('promo_banner_service_package_category as audience_category')
                                    ->whereColumn('audience_category.promo_banner_id', 'promo_banners.id'));
                        })
                        ->whereExists(fn (QueryBuilder $subscriptions) => $this->matchingSubscription(
                            $subscriptions,
                            $customerId,
                            'promo_banners.id',
                            'promo_banners.company_id',
                            'location_promo_banner',
                            'promo_banner_service_package_category',
                            'promo_banner_id',
                        ));
                });
        });
    }

    /** @param Builder<CustomerModel> $query
     * @return Builder<CustomerModel>
     */
    public function applyCustomerAudience(Builder $query, Announcement $announcement): Builder
    {
        return $this->applyTargeting(
            $query,
            (int) $announcement->company_id,
            $announcement->audience,
            $announcement->locations()->pluck('locations.id')->map(fn (mixed $id): int => (int) $id)->all(),
            $announcement->servicePackageCategories()->pluck('service_package_categories.id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
    }

    /**
     * How many customers an announcement with this targeting reaches: those with an active app account, the ones
     * SendAnnouncementNotifications writes to (keep the base query the same). It takes the targeting, not a saved
     * row, so the editor can say "untuk 8 pelanggan" before anything is saved. Ids of the current company only (the
     * caller validated them); acts in the current company.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $categoryIds
     */
    public function recipientCount(string $audience, array $locationIds, array $categoryIds): int
    {
        $accounts = CustomerModel::query()
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($user) => $user->where('is_active', true));

        return $this->applyTargeting($accounts, (int) CompanyService::currentId(), $audience, $locationIds, $categoryIds)->count();
    }

    /**
     * `all` reaches every customer; a targeted audience reaches the customers with an active or suspended subscription
     * under one of the locations (the location itself or anything below it) and in one of the categories, each list
     * counting only when it is not empty. A targeted audience without any target reaches nobody.
     *
     * @param  Builder<CustomerModel>  $query
     * @param  list<int>  $locationIds
     * @param  list<int>  $categoryIds
     * @return Builder<CustomerModel>
     */
    private function applyTargeting(Builder $query, int $companyId, string $audience, array $locationIds, array $categoryIds): Builder
    {
        if ($audience === 'all') {
            return $query;
        }

        if ($locationIds === [] && $categoryIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereExists(fn (QueryBuilder $subscriptions) => $this->matchingSubscriptionForTargets(
            $subscriptions,
            $companyId,
            $locationIds,
            $categoryIds,
        ));
    }

    /** @param Builder<Announcement> $query
     * @return Builder<Announcement>
     */
    public function applyAnnouncementAudience(Builder $query, int $customerId): Builder
    {
        return $query->where(function (Builder $audience) use ($customerId): void {
            $audience->where('audience', 'all')
                ->orWhere(function (Builder $targeted) use ($customerId): void {
                    $targeted->where('audience', 'targeted')
                        ->where(function (Builder $hasTarget): void {
                            $hasTarget->whereExists(fn (QueryBuilder $locations) => $locations
                                ->selectRaw('1')
                                ->from('announcement_location as audience_location')
                                ->whereColumn('audience_location.announcement_id', 'announcements.id'))
                                ->orWhereExists(fn (QueryBuilder $categories) => $categories
                                    ->selectRaw('1')
                                    ->from('announcement_service_package_category as audience_category')
                                    ->whereColumn('audience_category.announcement_id', 'announcements.id'));
                        })
                        ->whereExists(fn (QueryBuilder $subscriptions) => $this->matchingSubscription(
                            $subscriptions,
                            $customerId,
                            'announcements.id',
                            'announcements.company_id',
                            'announcement_location',
                            'announcement_service_package_category',
                            'announcement_id',
                        ));
                });
        });
    }

    private function matchingSubscription(
        QueryBuilder $query,
        int $customerId,
        string $itemIdColumn,
        string $companyIdColumn,
        string $locationPivot,
        string $categoryPivot,
        string $itemForeignKey,
    ): QueryBuilder {
        return $query
            ->selectRaw('1')
            ->from('service_subscriptions as audience_subscription')
            ->join('service_packages as audience_package', 'audience_package.id', '=', 'audience_subscription.service_package_id')
            ->leftJoin('locations as audience_pop', 'audience_pop.id', '=', 'audience_subscription.serving_pop_id')
            ->where('audience_subscription.customer_id', $customerId)
            ->whereColumn('audience_subscription.company_id', $companyIdColumn)
            ->whereNull('audience_subscription.deleted_at')
            ->whereIn('audience_subscription.status', ['active', 'suspended'])
            ->where(function (QueryBuilder $locations) use ($itemIdColumn, $locationPivot, $itemForeignKey): void {
                $locations->whereNotExists(fn (QueryBuilder $none) => $none
                    ->selectRaw('1')
                    ->from("{$locationPivot} as audience_location_none")
                    ->whereColumn("audience_location_none.{$itemForeignKey}", $itemIdColumn))
                    ->orWhereExists(fn (QueryBuilder $match) => $match
                        ->selectRaw('1')
                        ->from("{$locationPivot} as audience_location_match")
                        ->join('locations as audience_target_location', 'audience_target_location.id', '=', 'audience_location_match.location_id')
                        ->whereColumn("audience_location_match.{$itemForeignKey}", $itemIdColumn)
                        ->where(function (QueryBuilder $subtree): void {
                            $subtree->whereColumn('audience_target_location.id', 'audience_subscription.serving_pop_id')
                                ->orWhereRaw("LEFT(audience_pop.path, CHAR_LENGTH(audience_target_location.path) + 3) = CONCAT(audience_target_location.path, ' > ')");
                        }));
            })
            ->where(function (QueryBuilder $categories) use ($itemIdColumn, $categoryPivot, $itemForeignKey): void {
                $categories->whereNotExists(fn (QueryBuilder $none) => $none
                    ->selectRaw('1')
                    ->from("{$categoryPivot} as audience_category_none")
                    ->whereColumn("audience_category_none.{$itemForeignKey}", $itemIdColumn))
                    ->orWhereExists(fn (QueryBuilder $match) => $match
                        ->selectRaw('1')
                        ->from("{$categoryPivot} as audience_category_match")
                        ->whereColumn("audience_category_match.{$itemForeignKey}", $itemIdColumn)
                        ->whereColumn('audience_category_match.service_package_category_id', 'audience_package.service_package_category_id'));
            });
    }

    /**
     * @param  list<int>  $locationIds
     * @param  list<int>  $categoryIds
     */
    private function matchingSubscriptionForTargets(
        QueryBuilder $query,
        int $companyId,
        array $locationIds,
        array $categoryIds,
    ): QueryBuilder {
        $query->selectRaw('1')
            ->from('service_subscriptions as audience_subscription')
            ->join('service_packages as audience_package', 'audience_package.id', '=', 'audience_subscription.service_package_id')
            ->leftJoin('locations as audience_pop', 'audience_pop.id', '=', 'audience_subscription.serving_pop_id')
            ->whereColumn('audience_subscription.customer_id', 'customers.id')
            ->where('audience_subscription.company_id', $companyId)
            ->whereNull('audience_subscription.deleted_at')
            ->whereIn('audience_subscription.status', ['active', 'suspended']);

        if ($locationIds !== []) {
            $query->whereExists(fn (QueryBuilder $match) => $match
                ->selectRaw('1')
                ->from('locations as audience_target_location')
                ->whereIn('audience_target_location.id', $locationIds)
                ->where(function (QueryBuilder $subtree): void {
                    $subtree->whereColumn('audience_target_location.id', 'audience_subscription.serving_pop_id')
                        ->orWhereRaw("LEFT(audience_pop.path, CHAR_LENGTH(audience_target_location.path) + 3) = CONCAT(audience_target_location.path, ' > ')");
                }));
        }

        if ($categoryIds !== []) {
            $query->whereIn('audience_package.service_package_category_id', $categoryIds);
        }

        return $query;
    }
}
