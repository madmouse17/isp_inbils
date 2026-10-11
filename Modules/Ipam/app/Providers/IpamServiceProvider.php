<?php

namespace Modules\Ipam\Providers;

use Modules\Ipam\Console\ImportAssetIpsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class IpamServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Ipam';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'ipam';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ImportAssetIpsCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
