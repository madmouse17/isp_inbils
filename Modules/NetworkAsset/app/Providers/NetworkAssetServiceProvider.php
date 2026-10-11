<?php

namespace Modules\NetworkAsset\Providers;

use App\Models\Core\Company;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
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
