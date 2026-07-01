<?php

namespace App\Providers;

use App\Events\CompanyCreated;
use App\Listeners\SeedCompanyDefaults;
use App\Listeners\UpdateLastLoginAt;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\EmployeeEvaluationPolicy;
use App\Policies\LocationPolicy;
use App\Policies\RolePolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\UserPolicy;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Policies\CategoryPolicy;
use Modules\Inventory\Policies\ProductPolicy;
use Modules\Inventory\Policies\UnitPolicy;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Policies\NetworkAssetPolicy;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Policies\WorkOrderPolicy;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Policies\InvoicePolicy;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Policies\TicketPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Modules\Service\Policies\BandwidthProfilePolicy;
use Modules\Service\Policies\ServicePackagePolicy;
use Modules\Service\Policies\SLATierPolicy;
use Modules\Service\Policies\SpeedProfilePolicy;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(ServiceSubscription::class, SubscriptionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(BandwidthProfile::class, BandwidthProfilePolicy::class);
        Gate::policy(SpeedProfile::class, SpeedProfilePolicy::class);
        Gate::policy(SLATier::class, SLATierPolicy::class);
        Gate::policy(ServicePackage::class, ServicePackagePolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(NetworkAsset::class, NetworkAssetPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(EmployeeEvaluation::class, EmployeeEvaluationPolicy::class);

        Event::listen(Login::class, UpdateLastLoginAt::class);
        Event::listen(CompanyCreated::class, SeedCompanyDefaults::class);

        Vite::prefetch(concurrency: 3);
    }
}
