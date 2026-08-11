<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketValidationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_assign_rejects_cross_company_user(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $otherCompany = $this->createCompanyUser();
        $otherCompanyHandler = User::factory()->create([
            'company_id' => $otherCompany->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherCompanyHandler->assignRole('technician');

        $this->actingAs($admin)
            ->postJson(route('admin.tickets.assign', $ticket), [
                'handler_id' => $otherCompanyHandler->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['handler_id']);
    }

    public function test_assign_rejects_user_without_allowed_role(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);
        $handler = User::factory()->create([
            'company_id' => $admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.tickets.assign', $ticket), [
                'handler_id' => $handler->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['handler_id']);
    }

    public function test_create_sets_urgent_sla_to_half_the_category_default(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        Carbon::setTestNow($now = Carbon::parse('2026-07-27 08:00:00'));

        $category = TicketCategory::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-URG',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $ticket = TicketService::create([
            'title' => 'Urgent SLA',
            'description' => 'Check half SLA',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'urgent',
        ], $admin->id);

        Carbon::setTestNow();

        $this->assertTrue($ticket->sla_deadline->equalTo($now->copy()->addHours(12)));
    }

    public function test_add_comment_requires_permission(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $ticket = $this->makeOpenTicket($admin);

        $commenter = User::factory()->create([
            'company_id' => $admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($commenter)
            ->post(route('admin.tickets.comments.store', $ticket), [
                'body' => 'Need update',
                'is_internal' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('ticket_comments', [
            'ticket_id' => $ticket->id,
            'body' => 'Need update',
        ]);
    }

    public function test_ticket_store_rejects_subscription_for_wrong_customer(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        $customer = $this->companyCustomer($admin->company_id);
        $otherCustomer = $this->companyCustomer($admin->company_id);
        $package = $this->servicePackage($admin->company_id);
        $address = $this->customerAddress($admin->company_id, $otherCustomer->id);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $admin->company_id,
            'customer_id' => $otherCustomer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);
        $category = TicketCategory::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-TS',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $this->post(route('admin.tickets.store'), [
            'title' => 'Wrong parent ticket',
            'description' => 'Subscription belongs to other customer',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
        ])->assertSessionHasErrors(['subscription_id']);

        $this->assertDatabaseMissing('tickets', ['title' => 'Wrong parent ticket']);
        $this->assertSame(0, Ticket::withoutCompany()->where('title', 'Wrong parent ticket')->count());
    }

    public function test_ticket_store_accepts_same_company_references_and_sets_sla(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        Carbon::setTestNow($now = Carbon::parse('2026-07-27 08:00:00'));

        $customer = $this->companyCustomer($admin->company_id);
        $package = $this->servicePackage($admin->company_id);
        $address = $this->customerAddress($admin->company_id, $customer->id);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $admin->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);
        $location = Location::query()->create([
            'company_id' => $admin->company_id,
            'code' => 'LOC-TKT',
            'name' => 'Ticket Site',
            'type' => 'site',
            'path' => 'LOC-TKT',
            'is_active' => true,
        ]);
        $category = TicketCategory::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-TK',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $ticket = TicketService::create([
            'title' => 'Same company ticket',
            'description' => 'All refs are current tenant',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'urgent',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
        ], $admin->id);

        Carbon::setTestNow();

        $this->assertSame($admin->company_id, $ticket->company_id);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame($subscription->id, $ticket->subscription_id);
        $this->assertSame($location->id, $ticket->location_id);
        $this->assertTrue($ticket->sla_deadline->equalTo($now->copy()->addHours(12)));
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'company_id' => $admin->company_id]);
    }

    public function test_ticket_store_rejects_foreign_location_without_writing(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        $customer = $this->companyCustomer($admin->company_id);
        $package = $this->servicePackage($admin->company_id);
        $address = $this->customerAddress($admin->company_id, $customer->id);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $admin->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);
        $foreignCompany = Company::factory()->create();
        $foreignLocation = Location::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'code' => 'LOC-FOR',
            'name' => 'Foreign Location',
            'type' => 'site',
            'path' => 'LOC-FOR',
            'is_active' => true,
        ]);
        $category = TicketCategory::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-TF',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $this->post(route('admin.tickets.store'), [
            'title' => 'Foreign location ticket',
            'description' => 'Location is another tenant',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $foreignLocation->id,
        ])->assertSessionHasErrors(['location_id']);

        $this->assertDatabaseMissing('tickets', ['title' => 'Foreign location ticket']);
    }

    private function makeOpenTicket(User $admin): Ticket
    {
        $category = TicketCategory::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Ticket validation',
            'description' => 'Validation check',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $admin->id);
    }

    private function companyCustomer(int $companyId)
    {
        return Customer::factory()->create(['company_id' => $companyId]);
    }

    private function servicePackage(int $companyId)
    {
        return ServicePackageFactory::new()->create(['company_id' => $companyId]);
    }

    private function customerAddress(int $companyId, int $customerId)
    {
        return CustomerAddress::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
        ]);
    }
}
