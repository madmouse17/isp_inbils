<?php

namespace Tests\Unit\SPK;

use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Support\WorkOrderSite;
use Tests\TestCase;

/**
 * Where the technician goes (spec F4): the customer's installation address, never the POP the SPK is stocked from.
 */
class WorkOrderSiteTest extends TestCase
{
    private const POP = ['label' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => -6.9, 'lng' => 107.6];

    public function test_a_subscription_spk_goes_to_the_installation_address_and_shows_the_pop_apart(): void
    {
        $workOrder = $this->workOrder(
            address: ['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'city' => 'Jakarta', 'lat' => '-6.2000000', 'lng' => '106.8166667'],
            pop: ['name' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => '-6.9000000', 'lng' => '107.6000000'],
        );

        $this->assertSame(['label' => 'Rumah', 'address' => 'Jl. Mawar 1, Jakarta', 'lat' => -6.2, 'lng' => 106.8166667], WorkOrderSite::site($workOrder));
        $this->assertSame(self::POP, WorkOrderSite::pop($workOrder));
    }

    public function test_an_spk_without_a_subscription_keeps_its_location_as_the_site(): void
    {
        $workOrder = $this->workOrder(pop: ['name' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => '-6.9000000', 'lng' => '107.6000000']);

        $this->assertSame(self::POP, WorkOrderSite::site($workOrder));
        $this->assertNull(WorkOrderSite::pop($workOrder));
    }

    public function test_a_subscription_without_an_installation_address_falls_back_to_the_location(): void
    {
        $workOrder = $this->workOrder(subscribed: true, pop: ['name' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => '-6.9000000', 'lng' => '107.6000000']);

        $this->assertSame(self::POP, WorkOrderSite::site($workOrder));
        $this->assertNull(WorkOrderSite::pop($workOrder));
    }

    public function test_an_address_without_coordinates_keeps_its_text_and_never_borrows_the_pop(): void
    {
        $workOrder = $this->workOrder(
            address: ['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'city' => null, 'lat' => null, 'lng' => null],
            pop: ['name' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => '-6.9000000', 'lng' => '107.6000000'],
        );

        $this->assertSame(['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'lat' => null, 'lng' => null], WorkOrderSite::site($workOrder));
        $this->assertSame(self::POP, WorkOrderSite::pop($workOrder));
    }

    public function test_a_place_without_any_text_has_a_null_address(): void
    {
        $workOrder = $this->workOrder(address: ['label' => 'Rumah', 'address' => null, 'city' => null, 'lat' => '-6.2000000', 'lng' => '106.8166667']);

        $this->assertSame(['label' => 'Rumah', 'address' => null, 'lat' => -6.2, 'lng' => 106.8166667], WorkOrderSite::site($workOrder));
    }

    public function test_an_spk_with_nowhere_to_go_has_no_site_and_no_pop(): void
    {
        $workOrder = $this->workOrder();

        $this->assertNull(WorkOrderSite::site($workOrder));
        $this->assertNull(WorkOrderSite::pop($workOrder));
    }

    /**
     * @param  array<string, mixed>|null  $address  installation address columns; null when the subscription has none
     * @param  array<string, mixed>|null  $pop  location columns; null when the SPK has none
     */
    private function workOrder(?array $address = null, ?array $pop = null, bool $subscribed = false): WorkOrder
    {
        $workOrder = new WorkOrder();

        if ($address !== null || $subscribed) {
            $subscription = new ServiceSubscription();
            $subscription->setRelation('installationAddress', $address === null ? null : new CustomerAddress($address));
            $workOrder->setRelation('subscription', $subscription);
        } else {
            $workOrder->setRelation('subscription', null);
        }

        $workOrder->setRelation('location', $pop === null ? null : new Location($pop));

        return $workOrder;
    }
}
