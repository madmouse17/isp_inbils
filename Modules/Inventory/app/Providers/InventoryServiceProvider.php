<?php

namespace Modules\Inventory\Providers;

use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use App\Support\NumberSequences\ResetPeriod;
use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inventory';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inventory';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $registry = $this->app->make(NumberSequenceRegistry::class);
        $registry->register(
            new NumberSequenceDefinition('inventory_request', 'Permintaan Barang', 'inventory_requests', 'code', 255, 'REQ', '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}', ResetPeriod::Daily),
        );
        $registry->register(
            new NumberSequenceDefinition('product', 'Barang (SKU)', 'products', 'sku', 255, 'PRD', '{PREFIX}-{SEQ:5}', defaultAllowManual: true),
        );
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
