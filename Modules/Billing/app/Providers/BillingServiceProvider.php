<?php

namespace Modules\Billing\Providers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Console\CheckOverdueInvoices;
use Modules\Billing\Console\GenerateRecurringInvoices;
use Modules\Billing\Console\ReconcileMidtransPayments;
use Modules\Billing\Console\RotateLegacyCustomerPasswords;
use Modules\Billing\Listeners\ClearLegacyPasswordGate;
use Nwidart\Modules\Support\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Billing';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'billing';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GenerateRecurringInvoices::class,
        CheckOverdueInvoices::class,
        ReconcileMidtransPayments::class,
        RotateLegacyCustomerPasswords::class,
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

        // A completed password reset is what opens the portal for a legacy (flagged) customer login.
        Event::listen(PasswordReset::class, ClearLegacyPasswordGate::class);
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
