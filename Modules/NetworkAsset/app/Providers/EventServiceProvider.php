<?php

namespace Modules\NetworkAsset\Providers;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\NetworkAsset\Observers\ServiceSubscriptionNetworkObserver;
use Modules\NetworkAsset\Observers\SpeedProfileRadiusObserver;
use Modules\Service\Models\SpeedProfile;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Core never calls the network: the module observes the shared subscription model and the speed profile.
     *
     * @var array<class-string, class-string|array<int, class-string>>
     */
    protected $observers = [
        ServiceSubscription::class => [ServiceSubscriptionNetworkObserver::class],
        SpeedProfile::class => [SpeedProfileRadiusObserver::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
