<?php

namespace Modules\Service\Providers;

use Modules\Service\Console\CleanupPromoBannerImages;
use Modules\Service\Console\PublishScheduledAnnouncements;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ServiceServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Service';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'service';

    /** @var string[] */
    protected array $commands = [
        PublishScheduledAnnouncements::class,
        CleanupPromoBannerImages::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
