<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Permission\Models\Role;

/**
 * Company-scoped fixtures for the persona dashboard tests. Every record takes an explicit
 * company so two-company isolation cases never depend on the ambient test company.
 */
trait BuildsDashboardFixtures
{
    /** @var array<int, User> */
    private array $fixtureCreators = [];

    /** @var array<int, TicketCategory> */
    private array $fixtureCategories = [];

    /** @var array<int, Location> */
    private array $fixtureLocations = [];

    /** @var array<int, Customer> */
    private array $fixtureCustomers = [];

    protected function seedDashboardRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    protected function makeCompany(): Company
    {
        return Company::factory()->create(['is_active' => true]);
    }

    protected function userWithRole(Company $company, string $role, bool $active = true): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => $active,
        ]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        // Technician and customer accounts are app-only on the web. A permission-less extra role keeps
        // their permission sets reachable here without them being redirected to /app-only.
        if (in_array($role, ['technician', 'customer'], true)) {
            $user->assignRole(Role::findOrCreate('web-access', 'web'));
        }

        return $user;
    }

    /** @param  list<string>  $permissions */
    protected function userWithPermissions(Company $company, array $permissions, bool $active = true): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => $active,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function activeEmployeeProfile(User $user, string $status = 'active'): EmployeeProfile
    {
        return EmployeeProfile::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }

    protected function fixtureCreator(Company $company): User
    {
        return $this->fixtureCreators[$company->id] ??= User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    protected function fixtureLocation(Company $company): Location
    {
        return $this->fixtureLocations[$company->id] ??= Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-DASH-'.Str::upper(Str::random(6)),
            'name' => 'Dashboard Location',
            'type' => 'site',
            'path' => 'Dashboard',
            'is_active' => true,
        ]);
    }

    protected function fixtureCustomer(Company $company): Customer
    {
        return $this->fixtureCustomers[$company->id] ??= Customer::factory()->create(['company_id' => $company->id]);
    }

    private function fixtureCategory(Company $company): TicketCategory
    {
        return $this->fixtureCategories[$company->id] ??= TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Dashboard fixtures',
            'code' => 'DASH-'.Str::upper(Str::random(6)),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function ticket(Company $company, array $attributes = []): Ticket
    {
        return Ticket::query()->create(array_merge([
            'company_id' => $company->id,
            'category_id' => $this->fixtureCategory($company)->id,
            'code' => 'TKT-'.Str::upper(Str::random(8)),
            'title' => 'Dashboard fixture ticket',
            'description' => 'Fixture ticket for dashboard read-model coverage.',
            'source' => 'internal',
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->fixtureCreator($company)->id,
            'sla_deadline' => now()->addDay(),
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function workOrder(Company $company, array $attributes = []): WorkOrder
    {
        return WorkOrderFactory::new()->create(array_merge([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'draft',
            'customer_id' => $this->fixtureCustomer($company)->id,
            'location_id' => $this->fixtureLocation($company)->id,
            'created_by' => $this->fixtureCreator($company)->id,
            'assigned_to' => null,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function invoice(Company $company, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $this->fixtureCustomer($company)->id,
            'created_by' => $this->fixtureCreator($company)->id,
            'type' => 'one_time',
            'status' => 'sent',
            'issue_date' => now()->subDays(30)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'total' => '1000000.00',
            'paid_amount' => '0.00',
        ], $attributes));
    }

    /**
     * One stock row (product x location) for a fresh product.
     *
     * @param  array<string, mixed>  $productAttributes
     */
    protected function stockRow(Company $company, string $quantity, string $minStock, array $productAttributes = []): Stock
    {
        $product = Product::factory()->create(array_merge([
            'company_id' => $company->id,
            'min_stock' => $minStock,
            'is_active' => true,
        ], $productAttributes));

        return Stock::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $this->fixtureLocation($company)->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.00',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function inventoryRequest(Company $company, User $requester, string $status, array $attributes = []): InventoryRequest
    {
        return InventoryRequest::query()->create(array_merge([
            'company_id' => $company->id,
            'code' => 'REQ-'.Str::upper(Str::random(8)),
            'requested_by' => $requester->id,
            'warehouse_location_id' => $this->fixtureLocation($company)->id,
            'status' => $status,
            'reason' => 'Dashboard fixture request',
            'submitted_at' => now(),
        ], $attributes));
    }

    protected function returnCase(Company $company, User $technician, string $status): MaterialReturnCase
    {
        $request = $this->inventoryRequest($company, $technician, 'issued');

        return MaterialReturnCase::query()->create([
            'company_id' => $company->id,
            'inventory_request_id' => $request->id,
            'technician_id' => $technician->id,
            'status' => $status,
            'declaration_version' => 1,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function notification(User $user, array $data, ?Carbon $createdAt = null, ?Carbon $readAt = null): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-notification',
            'data' => $data,
            'read_at' => $readAt,
        ]);

        if ($createdAt !== null) {
            $notification->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $notification->fresh();
    }
}
