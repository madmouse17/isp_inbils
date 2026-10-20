<?php

namespace Tests\Feature\Api\V1\Matrix;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Payment;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * One tenant with real data behind every `{id}` of the mobile API, and the people who must never see it:
 * a peer of the same company and the same app, and the same two roles in another company.
 * Strings starting with SECRET- are planted where a response must not carry them.
 */
trait BuildsMatrixWorld
{
    use InteractsWithMidtrans;
    use InteractsWithMobileApi;

    protected const SECRET_SNAP = 'SECRET-snap-token-0001';

    protected const SECRET_NOTE = 'SECRET-internal-note-0002';

    protected const SECRET_INTERNAL_COMMENT = 'SECRET-internal-comment-0003';

    protected const SECRET_PPPOE = 'SECRET-pppoe-password-0004';

    protected const SECRET_TAX = 'SECRET-tax-id-0005';

    protected const SECRET_TWO_FACTOR = 'SECRET-two-factor-0006';

    /** The assigned technician's phone: the technician's own `me` shows it, no customer response may. */
    protected const SECRET_TECHNICIAN_PHONE = 'SECRET-technician-phone-0007';

    protected Company $companyA;

    protected Company $companyB;

    protected User $customerUser;

    protected Customer $customer;

    protected User $peerCustomerUser;

    protected User $foreignCustomerUser;

    protected User $technician;

    protected User $peerTechnician;

    protected User $foreignTechnician;

    protected string $customerToken;

    protected string $technicianToken;

    /** @var array<string, string> public ids of the seeded rows (a notification's own id is its UUID) */
    protected array $ids = [];

    /** The customer's subscription: its integer id is the foreign key of the visits and the banner targets below. */
    protected ServiceSubscription $subscription;

    protected function buildWorld(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();

        $this->companyA = $this->apiCompany();
        $this->companyB = $this->apiCompany();

        [$this->customerUser, $this->customer] = $this->customerLogin($this->companyA);
        [$this->peerCustomerUser] = $this->customerLogin($this->companyA);
        [$this->foreignCustomerUser] = $this->customerLogin($this->companyB);
        $this->technician = $this->technicianLogin($this->companyA);
        $this->peerTechnician = $this->technicianLogin($this->companyA);
        $this->foreignTechnician = $this->technicianLogin($this->companyB);

        foreach ([$this->customerUser, $this->technician] as $user) {
            $user->forceFill(['two_factor_secret' => self::SECRET_TWO_FACTOR, 'two_factor_recovery_codes' => [self::SECRET_TWO_FACTOR], 'two_factor_confirmed_at' => now()])->save();
        }
        $this->customer->forceFill(['tax_id' => self::SECRET_TAX, 'notes' => self::SECRET_NOTE])->save();
        $this->technician->employeeProfile()->update(['phone' => self::SECRET_TECHNICIAN_PHONE]);

        $this->customerToken = $this->issueToken($this->customerUser, 'customer', 'Owner phone');
        $this->technicianToken = $this->issueToken($this->technician, 'technician', 'Owner van');
        $this->ids['customer_device'] = $this->customerUser->tokens()->firstOrFail()->public_id;
        $this->ids['technician_device'] = $this->technician->tokens()->firstOrFail()->public_id;

        CompanyService::useCompanyForTests((int) $this->companyA->id);
        $this->buildBilling();
        $this->buildTicketing();
        $this->buildFieldWork();
        $this->buildAnnouncements();
        $this->buildNotifications();
    }

    private function buildBilling(): void
    {
        $companyId = $this->companyA->id;
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $this->customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $companyId])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $this->customer->id])->id,
            'status' => 'active',
        ]);
        NetworkAccount::factory()->create(['company_id' => $companyId, 'subscription_id' => $subscription->id, 'password' => self::SECRET_PPPOE]);
        $this->subscription = $subscription;
        $this->ids['subscription'] = $subscription->public_id;

        $invoice = InvoiceFactory::new()->create(['company_id' => $companyId, 'customer_id' => $this->customer->id, 'status' => 'sent', 'total' => 154600]);
        PaymentAttemptFactory::new()->forInvoice($invoice)->create(['snap_token' => self::SECRET_SNAP, 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/public-page']);
        Payment::create([
            'company_id' => $companyId,
            'invoice_id' => $invoice->id,
            'amount' => '1000.00',
            'method' => 'transfer',
            'reference' => 'BANK-REF-1',
            'paid_at' => now(),
            'received_by' => User::factory()->create(['company_id' => $companyId])->id,
            'notes' => self::SECRET_NOTE,
        ]);
        $this->ids['invoice'] = $invoice->public_id;
        $this->ids['paid_invoice'] = InvoiceFactory::new()->create(['company_id' => $companyId, 'customer_id' => $this->customer->id, 'status' => 'paid', 'total' => 154600, 'paid_amount' => 154600])->public_id;
    }

    private function buildTicketing(): void
    {
        $companyId = $this->companyA->id;
        $staff = User::factory()->create(['company_id' => $companyId, 'email_verified_at' => now(), 'is_active' => true]);
        $category = TicketCategory::query()->create([
            'company_id' => $companyId, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
        $this->ids['category'] = $category->public_id;
        $make = fn (array $overrides): Ticket => Ticket::query()->create([
            'company_id' => $companyId, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet down', 'description' => 'Offline',
            'source' => 'customer', 'category_id' => $category->id, 'status' => 'open', 'priority' => 'medium',
            'created_by' => $staff->id, 'sla_deadline' => now()->addDay(), ...$overrides,
        ]);

        $customerTicket = $make(['customer_id' => $this->customer->id]);
        TicketComment::query()->create(['ticket_id' => $customerTicket->id, 'author_id' => $staff->id, 'body' => self::SECRET_INTERNAL_COMMENT, 'is_internal' => true]);
        TicketComment::query()->create(['ticket_id' => $customerTicket->id, 'author_id' => $staff->id, 'body' => 'Public reply', 'is_internal' => false]);
        $this->ids['customer_ticket'] = $customerTicket->public_id;
        $this->ids['ticket_attachment'] = $customerTicket->addMediaFromString('png')->usingFileName('photo.png')
            ->withCustomProperties(['company_id' => $companyId])->toMediaCollection('attachments')->public_id;
        $this->ids['customer_attachment'] = $customerTicket->addMediaFromString('png')->usingFileName('mine.png')
            ->withCustomProperties(['company_id' => $companyId, 'uploaded_by' => $this->customerUser->id])->toMediaCollection('attachments')->public_id;

        $technicianTicket = $make([
            'source' => 'internal', 'status' => 'assigned', 'assigned_to' => $this->technician->id,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
        ]);
        $this->ids['technician_ticket'] = $technicianTicket->public_id;
        $this->ids['technician_attachment'] = $technicianTicket->addMediaFromString('png')->usingFileName('site.png')
            ->withCustomProperties(['company_id' => $companyId, 'uploaded_by' => $this->technician->id])->toMediaCollection('attachments')->public_id;
    }

    private function buildFieldWork(): void
    {
        $companyId = $this->companyA->id;
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $companyId, 'status' => 'in_progress', 'assigned_to' => $this->technician->id, 'created_by' => $this->technician->id,
        ]);
        $this->ids['work_order'] = $workOrder->public_id;
        $this->ids['evidence'] = $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $companyId, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
            ->toMediaCollection('evidence')->public_id;

        // A visit for the customer's subscription: the customer reads it, nobody else may.
        $this->ids['customer_work_order'] = WorkOrderFactory::new()->create([
            'company_id' => $companyId, 'status' => 'assigned', 'assigned_to' => $this->technician->id, 'created_by' => $this->technician->id,
            'customer_id' => $this->customer->id, 'subscription_id' => $this->subscription->id,
        ])->public_id;

        $location = Location::factory()->create(['company_id' => $companyId]);
        $unit = Unit::factory()->create(['company_id' => $companyId, 'symbol' => 'm'.Str::random(4)]);
        $product = Product::factory()->create([
            'company_id' => $companyId, 'unit_id' => $unit->id,
            'category_id' => Category::factory()->create(['company_id' => $companyId, 'unit_id' => $unit->id])->id,
        ]);
        $request = InventoryRequest::query()->create([
            'company_id' => $companyId, 'code' => 'REQ-'.Str::upper(Str::random(6)), 'requested_by' => $this->technician->id,
            'warehouse_location_id' => $location->id, 'source_type' => 'work_order', 'source_id' => $workOrder->id, 'status' => 'issued',
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $companyId, 'inventory_request_id' => $request->id, 'product_id' => $product->id,
            'source_location_id' => $location->id, 'quantity_requested' => 10, 'quantity_approved' => 10, 'quantity_issued' => 10,
        ]);
        $return = MaterialReturnService::createForIssuedRequest($request);
        $this->ids['material_return'] = $return->public_id;
        // The declare call names its lines: a body that passes validation leaves the tenancy check as the only 404.
        $this->ids['material_return_line'] = $return->lines()->firstOrFail()->public_id;

        $this->ids['work_order_item'] = WorkOrderItem::query()->create([
            'company_id' => $companyId, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'quantity_used' => 2,
        ])->public_id;
        $this->ids['inventory_request'] = InventoryRequest::query()->create([
            'company_id' => $companyId, 'code' => 'REQ-'.Str::upper(Str::random(6)), 'requested_by' => $this->technician->id,
            'warehouse_location_id' => $location->id, 'status' => 'submitted', 'reason' => 'Need cable', 'submitted_at' => now(),
        ])->public_id;
    }

    private function buildNotifications(): void
    {
        $this->ids['customer_notification'] = $this->customer->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test-notification', 'data' => ['type' => 'invoice_issued', 'invoice_id' => $this->ids['invoice']],
        ])->id;
        $this->ids['technician_notification'] = $this->technician->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test-notification', 'data' => ['type' => 'ticket_assigned', 'ticket_id' => $this->ids['technician_ticket']],
        ])->id;
    }

    /** The route's name without the api.v1. prefix. */
    protected function shortName(LaravelRoute $route): string
    {
        return Str::after((string) $route->getName(), 'api.v1.');
    }

    /** @return list<LaravelRoute> */
    protected function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'),
        ));
    }

    /** The public auth endpoints: no bearer token needed, so no 401 for a missing one. */
    protected function isPublic(LaravelRoute $route): bool
    {
        return in_array($this->shortName($route), ['auth.login', 'auth.two-factor', 'auth.forgot-password'], true);
    }

    /** 'customer', 'technician' or null (any signed-in app user), from the guard groups the route sits in. */
    protected function fenceOf(LaravelRoute $route): ?string
    {
        $middleware = $route->gatherMiddleware();

        return in_array('api.customer', $middleware, true) ? 'customer' : (in_array('api.technician', $middleware, true) ? 'technician' : null);
    }

    /** True for every route that carries Idempotency-Key (everything behind api.any, api.customer, api.technician). */
    protected function needsIdempotencyKey(LaravelRoute $route): bool
    {
        $middleware = $route->gatherMiddleware();

        return array_intersect(['api.any', 'api.customer', 'api.technician'], $middleware) !== []
            && array_diff($route->methods(), ['GET', 'HEAD']) !== [];
    }

    protected function method(LaravelRoute $route): string
    {
        return array_values(array_diff($route->methods(), ['HEAD']))[0];
    }

    /**
     * The uri with every placeholder filled: from the seeded rows when $real, else with ids that match nothing.
     */
    private function buildAnnouncements(): void
    {
        $announcement = Announcement::factory()->published()->targeted()->create(['company_id' => $this->companyA->id]);
        $banner = PromoBanner::factory()->published()->create(['company_id' => $this->companyA->id, 'audience' => 'targeted']);
        $categoryId = (int) $this->subscription->servicePackage()->value('service_package_category_id');
        $announcement->servicePackageCategories()->sync([$categoryId]);
        $banner->servicePackageCategories()->sync([$categoryId]);
        $this->ids['announcement'] = $announcement->public_id;
        $this->ids['promo_banner'] = $banner->public_id;
    }

    protected function urlOf(LaravelRoute $route, bool $real): string
    {
        $values = $real ? $this->realIds($this->shortName($route)) : [];
        // A well-formed id that names nothing: an integer would 404 at the route constraint, whatever the controller does.
        $nothing = $this->shortName($route) === 'notifications.read' ? (string) Str::uuid() : PublicId::new();
        $position = 0;

        return (string) preg_replace_callback('/\{\w+\}/', fn (): string => (string) ($values[$position++] ?? $nothing), Str::after($route->uri(), 'api/v1/'));
    }

    /** @return list<string> the seeded ids for a route's placeholders, in uri order */
    protected function realIds(string $name): array
    {
        return match ($name) {
            'customer.subscriptions.show', 'customer.subscriptions.connection' => [$this->ids['subscription']],
            'customer.announcements.show' => [$this->ids['announcement']],
            'customer.work-orders.show' => [$this->ids['customer_work_order']],
            'customer.promo-banners.tap' => [$this->ids['promo_banner']],
            'customer.invoices.show', 'customer.invoices.pay' => [$this->ids['invoice']],
            'customer.invoices.receipt' => [$this->ids['paid_invoice']],
            'customer.tickets.show', 'customer.tickets.comments.store', 'customer.tickets.attachments.store' => [$this->ids['customer_ticket']],
            'customer.tickets.attachments.destroy' => [$this->ids['customer_ticket'], $this->ids['customer_attachment']],
            'devices.destroy' => [$this->ids['customer_device']],
            'media.show' => [$this->ids['ticket_attachment']],
            'notifications.read' => [$this->ids['customer_notification']],
            'technician.tickets.show', 'technician.tickets.start', 'technician.tickets.resolve', 'technician.tickets.comments.store', 'technician.tickets.attachments.store' => [$this->ids['technician_ticket']],
            'technician.tickets.attachments.destroy' => [$this->ids['technician_ticket'], $this->ids['technician_attachment']],
            'technician.work-orders.show', 'technician.work-orders.start', 'technician.work-orders.en-route', 'technician.work-orders.submit', 'technician.work-orders.evidence.store', 'technician.work-orders.items.store', 'technician.work-orders.network-credentials' => [$this->ids['work_order']],
            'technician.work-orders.evidence.destroy' => [$this->ids['work_order'], $this->ids['evidence']],
            'technician.work-orders.items.destroy' => [$this->ids['work_order'], $this->ids['work_order_item']],
            'technician.material-returns.show', 'technician.material-returns.declare' => [$this->ids['material_return']],
            'technician.inventory-requests.cancel' => [$this->ids['inventory_request']],
            default => [],
        };
    }
}
