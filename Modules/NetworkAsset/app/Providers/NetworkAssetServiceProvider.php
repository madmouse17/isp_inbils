<?php

namespace Modules\NetworkAsset\Providers;

use App\Models\Core\Company;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\NetworkAsset\Console\SyncNetworkDevices;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NetworkAssetServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'NetworkAsset';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'networkasset';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SyncNetworkDevices::class,
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

    public function boot(): void
    {
        parent::boot();

        // A router sends every isolated subscriber to the isolir page, often all behind one NAT address: a real code
        // gets a generous allowance per address, and only unknown or inactive codes (guessing) count against a strict one.
        RateLimiter::for('isolir', function (Request $request): Limit {
            $code = $request->route('company');
            $code = $code instanceof Company ? (string) $code->code : (string) $code;

            return Company::query()->where('code', $code)->where('is_active', true)->exists()
                ? Limit::perMinute(600)->by('isolir|'.$code.'|'.$request->ip())
                : Limit::perMinute(20)->by('isolir-miss|'.$request->ip());
        });

        // A worker lives for hours and keeps every database link it opened. The link of one company must not serve the next
        // job, and a row saved in the web process (host, database, user) is only purged there: the worker starts each loop
        // from the rows. A listener that returns a value would stop the other `Looping` listeners, so this returns nothing.
        Queue::looping(function (): void {
            foreach (array_keys(DB::getConnections()) as $name) {
                if (str_starts_with($name, 'radius_')) {
                    DB::purge($name);
                }
            }
        });

        $this->app->make(NumberSequenceRegistry::class)->register(
            new NumberSequenceDefinition('network_asset', 'Aset Jaringan', 'network_assets', 'code', 255, 'NA', '{PREFIX}-{YYYY}-{SEQ:5}'),
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
