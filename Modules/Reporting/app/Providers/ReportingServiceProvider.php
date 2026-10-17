<?php

namespace Modules\Reporting\Providers;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Inertia\Inertia;
use Modules\Reporting\Services\TechnicianReportAccess;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Reporting';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'reporting';

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

        // Sidebar flag for Technician Performance: same rule as the route's own 403, shared from the
        // module so Core never imports it. Lazy: only evaluated when a page renders. The prop reaches every
        // Inertia response, the operator panel's too, where the signed-in user is a platform admin with no
        // tenant report: only a tenant user is asked.
        Inertia::share('canTechnicianReport', function (): bool {
            $user = request()->user();

            return $user instanceof User && TechnicianReportAccess::allows($user);
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
