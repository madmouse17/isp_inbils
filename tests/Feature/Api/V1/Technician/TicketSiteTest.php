<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * F6-D08: the technician sees where to go. `site` is the place the check-in is measured against: the installation address of
 * the subscription, else the location (POP) of the ticket, and it says which one it is. `location` stays the POP.
 */
class TicketSiteTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $technician;

    private string $token;

    private Customer $customer;

    private TicketCategory $category;

    private Location $pop;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $company = $this->apiCompany();
        $this->companyId = (int) $company->id;
        $this->technician = $this->technicianLogin($company);
        $this->token = $this->issueToken($this->technician, 'technician');
        $this->customer = Customer::factory()->create(['company_id' => $this->companyId]);
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->companyId, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
        $this->pop = Location::factory()->create(['company_id' => $this->companyId, 'name' => 'POP Pusat', 'address' => 'Jl. Mawar 1', 'lat' => -6.1753924, 'lng' => 106.8271528]);
    }

    public function test_the_site_is_the_installation_address_and_the_pop_stays_the_location(): void
    {
        $ticket = $this->ticket($this->subscriptionAt('Jl. Kenanga 7', -6.2, 106.8), $this->pop);

        $this->show($ticket)
            ->assertOk()
            ->assertJsonPath('data.site', ['address' => 'Jl. Kenanga 7', 'lat' => -6.2, 'lng' => 106.8, 'source' => 'installation'])
            ->assertJsonPath('data.location.name', 'POP Pusat')
            ->assertJsonPath('data.location.lat', -6.1753924);
    }

    public function test_without_a_subscription_the_site_is_the_pop(): void
    {
        $ticket = $this->ticket(null, $this->pop);

        $this->show($ticket)
            ->assertOk()
            ->assertJsonPath('data.site', ['address' => 'Jl. Mawar 1', 'lat' => -6.1753924, 'lng' => 106.8271528, 'source' => 'pop']);
    }

    public function test_a_ticket_with_neither_has_no_site(): void
    {
        $ticket = $this->ticket(null, null);

        $this->show($ticket)->assertOk()->assertJsonPath('data.site', null)->assertJsonPath('data.location', null);
    }

    public function test_the_customer_carries_a_whatsapp_number_only_for_an_indonesian_mobile(): void
    {
        $this->customer->update(['phone' => '0812-3456-7890']);
        $this->show($this->ticket(null, null))->assertOk()->assertJsonPath('data.customer.phone_e164', '+6281234567890');

        $this->customer->update(['phone' => '021 555 1234']);
        $this->show($this->ticket(null, null))->assertOk()->assertJsonPath('data.customer.phone_e164', null);
    }

    public function test_an_installation_address_without_coordinates_is_still_the_site_the_check_in_uses(): void
    {
        $ticket = $this->ticket($this->subscriptionAt('Jl. Melati 3', null, null), $this->pop);

        $this->show($ticket)
            ->assertOk()
            ->assertJsonPath('data.site', ['address' => 'Jl. Melati 3', 'lat' => null, 'lng' => null, 'source' => 'installation']);

        $this->api('POST', "technician/tickets/{$ticket->public_id}/start", ['check_in' => ['lat' => -6.1753924, 'lng' => 106.8271528]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.distance_m', null);
    }

    public function test_the_check_in_is_measured_to_the_place_the_site_names(): void
    {
        $ticket = $this->ticket($this->subscriptionAt('Jl. Kenanga 7', -6.2, 106.8), $this->pop);

        $this->api('POST', "technician/tickets/{$ticket->public_id}/start", ['check_in' => ['lat' => -6.2, 'lng' => 106.8]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'on_progress')
            ->assertJsonPath('data.check_in.distance_m', 0)
            ->assertJsonPath('data.site.source', 'installation')
            ->assertJsonPath('data.site.lat', -6.2);
    }

    private function show(Ticket $ticket): TestResponse
    {
        return $this->api('GET', "technician/tickets/{$ticket->public_id}", token: $this->token);
    }

    private function subscriptionAt(string $address, ?float $lat, ?float $lng): ServiceSubscription
    {
        $installation = CustomerAddress::factory()->create(['company_id' => $this->companyId, 'customer_id' => $this->customer->id, 'address' => $address, 'lat' => $lat, 'lng' => $lng]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->companyId, 'customer_id' => $this->customer->id, 'installation_address_id' => $installation->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->companyId])->id, 'status' => 'active',
        ]);
    }

    private function ticket(?ServiceSubscription $subscription, ?Location $location): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->companyId, 'code' => 'TKT-'.Str::upper(Str::random(8)), 'title' => 'Internet mati', 'source' => 'customer',
            'category_id' => $this->category->id, 'status' => 'assigned', 'priority' => 'medium', 'customer_id' => $this->customer->id,
            'subscription_id' => $subscription?->id, 'location_id' => $location?->id, 'assigned_to' => $this->technician->id,
            'created_by' => $this->technician->id, 'sla_deadline' => now()->addDay(),
        ]);
    }
}
