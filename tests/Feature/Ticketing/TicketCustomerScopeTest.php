<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

class TicketCustomerScopeTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private User $customerA;

    private Ticket $ticketA;

    private Ticket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $category = TicketCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        [$this->customerA, $customerAId] = $this->portalUser();
        [, $customerBId] = $this->portalUser();

        $this->ticketA = $this->makeTicket($category, $customerAId);
        $this->ticketB = $this->makeTicket($category, $customerBId);
    }

    public function test_customer_sees_only_own_tickets_in_index(): void
    {
        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $this->ticketA->public_id));
    }

    public function test_customer_can_view_own_ticket(): void
    {
        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.show', $this->ticketA))
            ->assertOk();
    }

    public function test_customer_cannot_view_other_customers_ticket(): void
    {
        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.show', $this->ticketB))
            ->assertNotFound();
    }

    public function test_customer_can_comment_on_own_ticket(): void
    {
        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.comments.store', $this->ticketA), ['body' => 'Mine'])
            ->assertRedirect();

        $this->assertSame(1, $this->ticketA->comments()->count());
    }

    public function test_customer_cannot_comment_on_other_customers_ticket(): void
    {
        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.comments.store', $this->ticketB), ['body' => 'Intruder'])
            ->assertNotFound();

        $this->assertSame(0, $this->ticketB->comments()->count());
    }

    public function test_customer_cannot_upload_attachment_to_other_customers_ticket(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Permission::findOrCreate('ticket.attachment.upload', 'web');
        $this->customerA->givePermissionTo('ticket.attachment.upload');

        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.attachments.store', $this->ticketB), [
                'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            ])
            ->assertNotFound();

        $this->assertCount(0, $this->ticketB->getMedia('attachments'));
    }

    public function test_customer_does_not_receive_internal_comments(): void
    {
        TicketService::addComment($this->ticketA, ['body' => 'Staff only note', 'is_internal' => true], $this->admin->id);
        TicketService::addComment($this->ticketA, ['body' => 'Public reply', 'is_internal' => false], $this->admin->id);

        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.show', $this->ticketA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.data.comments', 1)
                ->where('ticket.data.comments.0.body', 'Public reply'));

        $this->actingAs($this->admin)
            ->get(route('admin.tickets.show', $this->ticketA))
            ->assertInertia(fn (Assert $page) => $page->has('ticket.data.comments', 2));
    }

    public function test_customer_comment_is_never_internal(): void
    {
        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.comments.store', $this->ticketA), ['body' => 'Sneaky', 'is_internal' => true])
            ->assertRedirect();

        $this->assertFalse((bool) $this->ticketA->comments()->firstOrFail()->is_internal);
    }

    public function test_customer_ticket_is_bound_to_own_customer(): void
    {
        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.store'), $this->payload(['customer_id' => null]))
            ->assertRedirect();

        $this->assertSame(
            $this->customerA->customer->id,
            Ticket::query()->where('title', 'Portal ticket')->firstOrFail()->customer_id,
        );
    }

    public function test_customer_cannot_create_ticket_for_another_customer(): void
    {
        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.store'), $this->payload(['customer_id' => $this->customerPublicId($this->ticketB->customer_id)]))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Ticket::query()->where('title', 'Portal ticket')->count());
    }

    public function test_customer_cannot_attach_another_customers_subscription_or_asset(): void
    {
        $foreignSubscription = ServiceSubscription::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->ticketB->customer_id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->admin->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $this->admin->company_id,
                'customer_id' => $this->ticketB->customer_id,
            ])->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);

        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.store'), $this->payload(['subscription_id' => $foreignSubscription->public_id]))
            ->assertSessionHasErrors('subscription_id');

        $foreignAsset = NetworkAssetFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->ticketB->customer_id,
        ]);
        $sharedAsset = NetworkAssetFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => null,
        ]);

        foreach ([$foreignAsset, $sharedAsset] as $asset) {
            $this->actingAs($this->customerA)
                ->post(route('admin.tickets.store'), $this->payload(['network_asset_id' => $asset->public_id]))
                ->assertSessionHasErrors('network_asset_id');
        }

        $this->assertSame(0, Ticket::query()->where('title', 'Portal ticket')->count());
    }

    public function test_create_form_lists_only_own_customer_data(): void
    {
        $own = $this->customerA->customer;
        // Another customer of the same company whose name also matches the search: only the own one may be offered.
        Customer::factory()->create(['company_id' => $own->company_id, 'name' => $own->name.' Lain']);

        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.create', ['customer_search' => $own->name]), $this->partialReload('Admin/Tickets/Create', 'customerOptions'))
            ->assertOk()
            ->assertJsonCount(1, 'props.customerOptions')
            ->assertJsonPath('props.customerOptions.0.value', $own->public_id);
    }

    public function test_customer_create_form_offers_no_locations(): void
    {
        $this->makeLocation('LOC-FORM');

        $this->actingAs($this->customerA)
            ->get(route('admin.tickets.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canChooseLocation', false)
                ->has('locations.data', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.tickets.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canChooseLocation', true)
                ->has('locations.data', 1));
    }

    public function test_customer_supplied_location_is_ignored(): void
    {
        $location = $this->makeLocation('LOC-PICK');

        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.store'), $this->payload(['location_id' => $location->public_id]))
            ->assertRedirect();

        $this->assertNull(Ticket::query()->where('title', 'Portal ticket')->firstOrFail()->location_id);
    }

    public function test_customer_ticket_location_comes_from_subscription_service_location(): void
    {
        $pop = $this->makeLocation('LOC-POP');
        $other = $this->makeLocation('LOC-OTHER');
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customerA->customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->admin->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $this->admin->company_id,
                'customer_id' => $this->customerA->customer->id,
            ])->id,
            'serving_pop_id' => $pop->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);

        $this->actingAs($this->customerA)
            ->post(route('admin.tickets.store'), $this->payload([
                'subscription_id' => $subscription->public_id,
                'location_id' => $other->public_id,
            ]))
            ->assertRedirect();

        $this->assertSame($pop->id, Ticket::query()->where('title', 'Portal ticket')->firstOrFail()->location_id);
    }

    public function test_staff_can_still_choose_ticket_location(): void
    {
        $location = $this->makeLocation('LOC-STAFF');

        $this->actingAs($this->admin)
            ->post(route('admin.tickets.store'), $this->payload([
                'customer_id' => $this->customerPublicId($this->ticketB->customer_id),
                'location_id' => $location->public_id,
            ]))
            ->assertRedirect();

        $this->assertSame($location->id, Ticket::query()->where('title', 'Portal ticket')->firstOrFail()->location_id);
    }

    private function makeLocation(string $code): Location
    {
        return Location::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $code,
            'type' => 'site',
            'path' => $code,
            'is_active' => true,
        ]);
    }

    public function test_staff_still_sees_every_ticket(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 2));

        $this->actingAs($this->admin)
            ->get(route('admin.tickets.show', $this->ticketB))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.tickets.comments.store', $this->ticketB), ['body' => 'Staff'])
            ->assertRedirect();
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Portal ticket',
            'description' => 'From portal',
            'source' => 'customer',
            'category_id' => TicketCategory::withoutCompany()->findOrFail($this->ticketA->category_id)->public_id,
            'priority' => 'medium',
            'customer_id' => $this->customerA->customer->public_id,
        ], $overrides);
    }

    private function customerPublicId(int $customerId): string
    {
        return Customer::withoutCompany()->findOrFail($customerId)->public_id;
    }

    /** @return array{0: User, 1: int} */
    private function portalUser(): array
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('customer');
        $customer = Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => $user->id,
        ]);

        return [$user, $customer->id];
    }

    private function makeTicket(TicketCategory $category, int $customerId): Ticket
    {
        return TicketService::create([
            'title' => 'Ticket '.uniqid(),
            'description' => 'Scope check',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customerId,
        ], $this->admin->id);
    }
}
