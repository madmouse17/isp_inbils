<?php

namespace App\Providers;

use App\Events\CompanyCreated;
use App\Listeners\SeedCompanyDefaults;
use App\Listeners\UpdateLastLoginAt;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\NumberSequence;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DocumentTypePolicy;
use App\Policies\EmployeeEvaluationPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\LocationPolicy;
use App\Policies\NumberSequencePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\RolePolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Policies\InvoicePolicy;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Policies\CategoryPolicy;
use Modules\Inventory\Policies\InventoryRequestPolicy;
use Modules\Inventory\Policies\MaterialReturnCasePolicy;
use Modules\Inventory\Policies\ProductPolicy;
use Modules\Inventory\Policies\UnitPolicy;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Policies\IpAddressPolicy;
use Modules\Ipam\Policies\IpPrefixPolicy;
use Modules\Ipam\Policies\VlanPolicy;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Policies\NetworkAssetPolicy;
use Modules\NetworkAsset\Policies\OdnPortPolicy;
use Modules\NetworkAsset\Policies\OdnSplitterPolicy;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Modules\Service\Policies\BandwidthProfilePolicy;
use Modules\Service\Policies\ServicePackagePolicy;
use Modules\Service\Policies\SLATierPolicy;
use Modules\Service\Policies\SpeedProfilePolicy;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Policies\WorkOrderPolicy;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Policies\TicketCategoryPolicy;
use Modules\Ticketing\Policies\TicketPolicy;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NumberSequenceRegistry::class);
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
        Gate::policy(InventoryRequest::class, InventoryRequestPolicy::class);
        Gate::policy(MaterialReturnCase::class, MaterialReturnCasePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(NetworkAsset::class, NetworkAssetPolicy::class);
        Gate::policy(IpPrefix::class, IpPrefixPolicy::class);
        Gate::policy(IpAddress::class, IpAddressPolicy::class);
        Gate::policy(Vlan::class, VlanPolicy::class);
        Gate::policy(OdnPort::class, OdnPortPolicy::class);
        Gate::policy(OdnSplitter::class, OdnSplitterPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(TicketCategory::class, TicketCategoryPolicy::class);
        Gate::policy(EmployeeEvaluation::class, EmployeeEvaluationPolicy::class);
        Gate::policy(OrganizationUnit::class, OrganizationPolicy::class);
        Gate::policy(EmployeeProfile::class, EmployeePolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(NumberSequence::class, NumberSequencePolicy::class);
        Gate::policy(DocumentType::class, DocumentTypePolicy::class);

        Event::listen(Login::class, UpdateLastLoginAt::class);
        Event::listen(CompanyCreated::class, SeedCompanyDefaults::class);
        $this->registerWhatsAppRateLimiters();
        $this->registerNumberSequences();
        $this->registerApiRateLimiters();
        $this->configureApiDocumentation();

        Activity::saving(function (Activity $activity) {
            $subject = $activity->relationLoaded('subject')
                ? $activity->getRelation('subject')
                : $activity->subject;

            $causer = $activity->relationLoaded('causer')
                ? $activity->getRelation('causer')
                : $activity->causer;

            $resolvedCompanyId = AuditService::resolveCompanyId(
                subject: $subject,
                properties: $activity->properties?->toArray() ?? [],
                explicitCompanyId: $activity->getAttribute('company_id') ? (int) $activity->getAttribute('company_id') : null
            );

            if ($resolvedCompanyId === null && $causer instanceof User) {
                $resolvedCompanyId = CompanyService::idFor($causer);
            }

            if ($resolvedCompanyId !== null) {
                $activity->setAttribute('company_id', $resolvedCompanyId);
                if ($activity->properties) {
                    $props = $activity->properties->toArray();
                    $props['company_id'] = $resolvedCompanyId;
                    $activity->properties = collect($props);
                }
            } else {
                $activity->setAttribute('company_id', null);
                if ($activity->properties && isset($activity->properties['company_id'])) {
                    $props = $activity->properties->toArray();
                    unset($props['company_id']);
                    $activity->properties = collect($props);
                }
            }
        });

        if (! $this->app->environment('e2e')) {
            Vite::prefetch(concurrency: 3);
        }
    }

    /** The numbered entities of the core; each module registers its own from its provider. */
    private function registerNumberSequences(): void
    {
        $registry = $this->app->make(NumberSequenceRegistry::class);

        $registry->register(new NumberSequenceDefinition('customer', 'Pelanggan', 'customers', 'code', 255, 'CUS', '{PREFIX}-{SEQ:5}'));
        $registry->register(new NumberSequenceDefinition('employee', 'Karyawan', 'employee_profiles', 'employee_number', 255, 'EMP', '{PREFIX}-{SEQ:4}'));
        $registry->register(new NumberSequenceDefinition('subscription', 'Langganan', 'service_subscriptions', 'code', 255, 'SUB', '{PREFIX}-{YYYY}-{SEQ:5}'));
    }

    /**
     * The WhatsApp queue's send rate per company, and the throttle of Meta's webhook. As with Midtrans, only
     * requests that fail the credential check count against the throttle: Meta's own calls are never
     * throttled, so junk that shares their apparent address cannot lock them out.
     */
    private function registerWhatsAppRateLimiters(): void
    {
        // The queue middleware passes the job (a SendQueuedNotifications) to the callback.
        RateLimiter::for('whatsapp-send', fn (object $job): Limit => Limit::perSecond((int) config('whatsapp.rate_per_second'))
            ->by('company:'.data_get($job, 'notification.companyId')));

        RateLimiter::for('whatsapp-webhook', function (Request $request): Limit|Unlimited {
            $client = app(WhatsAppCloudClient::class);
            // PHP turns the dots of Meta's hub.verify_token query parameter into underscores.
            $token = $request->query('hub_verify_token');
            $valid = $request->isMethod('GET')
                ? is_string($token) && $client->isValidVerifyToken($token)
                : $client->isValidSignature($request->getContent(), $request->header('X-Hub-Signature-256'));

            return $valid ? Limit::none() : Limit::perMinute(30)->by($request->ip());
        });
    }

    /**
     * Bearer security for the OpenAPI export of the mobile API (config/scramble.php holds the rest). Scramble is a
     * dev dependency, so production (composer install --no-dev) skips this.
     */
    private function configureApiDocumentation(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
                // Relative, so the export does not change with APP_URL.
                $openApi->servers = [Server::make('/api/v1')];

                // Scramble documents Laravel's {message, errors} shape; RenderApiError adds a stable code to every error.
                foreach ($openApi->components->responses as $response) {
                    $response->getContent('application/json')->type
                        ->addProperty('code', (new StringType())->setDescription('Stable error code; see the API description.'))
                        ->addRequired(['code']);
                }
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
                // The route's own middleware list holds the group names (api.any, ...); the resolved list holds auth:sanctum.
                $guarded = in_array(
                    Authenticate::class.':sanctum',
                    app('router')->gatherRouteMiddleware($routeInfo->route),
                    true,
                );

                if (! $guarded) {
                    $operation->security = [];
                }
            });
    }

    /**
     * Limiters of the mobile API. `api` covers every call (the api group throttles with it): per token once signed
     * in, per address before that. The others are attached by the routes they name. Login and two-factor also keep
     * the web's own budgets (per e-mail and address, per user), shared with the web through the same keys.
     */
    private function registerApiRateLimiters(): void
    {
        $perUser = fn (Request $request): string => 'user:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken()?->getKey();

            return Limit::perMinute(120)->by($token ? 'token:'.$token : 'ip:'.$request->ip());
        });
        RateLimiter::for('api-login', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('api-forgot-password', fn (Request $request): Limit => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('api-pay', fn (Request $request): Limit => Limit::perMinute(6)->by($perUser($request)));
        RateLimiter::for('api-upload', fn (Request $request): Limit => Limit::perMinute(30)->by($perUser($request)));
    }
}
