<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * An SPK names a customer and may name one of that customer's subscriptions, whatever its type: the pair is checked on
 * create and on every update that changes either side, so the invoice made from the SPK bills the right customer.
 */
class WorkOrderSubscriptionPairTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $user;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createCompanyUser();
        $this->other = Company::factory()->create(['is_active' => true]);
    }

    /** @return array<string, array{0: string}> */
    public static function types(): array
    {
        return [
            'installation' => ['installation'],
            'maintenance' => ['maintenance'],
            'upgrade_service' => ['upgrade_service'],
            'relocation' => ['relocation'],
        ];
    }

    #[DataProvider('types')]
    public function test_store_keeps_the_subscription_of_the_chosen_customer_for_every_type(string $type): void
    {
        $subscription = $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->post(route('admin.spk.store'), $this->payload($type, $subscription->customer_id, $subscription->id))
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_orders', ['type' => $type, 'customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id]);
    }

    #[DataProvider('types')]
    public function test_store_refuses_a_subscription_of_another_customer_for_every_type(string $type): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $theirs = $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->postJson(route('admin.spk.store'), $this->payload($type, $mine->customer_id, $theirs->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_store_refuses_a_soft_deleted_subscription(): void
    {
        $subscription = $this->subscriptionIn($this->user->company);
        $subscription->delete();

        $this->actingAs($this->user)
            ->postJson(route('admin.spk.store'), $this->payload('maintenance', $subscription->customer_id, $subscription->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');
    }

    public function test_store_without_a_customer_or_without_a_subscription_stays_possible_for_maintenance(): void
    {
        $subscription = $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->post(route('admin.spk.store'), $this->payload('maintenance', $subscription->customer_id, null))
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHasNoErrors();
        $this->post(route('admin.spk.store'), $this->payload('maintenance', null, $subscription->id))
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_update_refuses_a_subscription_of_another_customer_and_keeps_the_pair(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $theirs = $this->subscriptionIn($this->user->company);
        $workOrder = $this->workOrder($mine);

        $this->actingAs($this->user)
            ->putJson(route('admin.spk.update', $workOrder), ['title' => 'Ganti', 'customer_id' => $mine->customer_id, 'subscription_id' => $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $this->assertSame($mine->id, $workOrder->fresh()->subscription_id);
    }

    public function test_update_checks_the_pair_the_request_would_store_when_it_sends_only_one_side(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $theirs = $this->subscriptionIn($this->user->company);
        $workOrder = $this->workOrder($mine);

        $this->actingAs($this->user)
            ->putJson(route('admin.spk.update', $workOrder), ['title' => 'Ganti', 'subscription_id' => $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $this->putJson(route('admin.spk.update', $workOrder), ['title' => 'Ganti', 'customer_id' => $theirs->customer_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $fresh = $workOrder->fresh();
        $this->assertSame([$mine->customer_id, $mine->id], [$fresh->customer_id, $fresh->subscription_id]);
    }

    public function test_update_saves_a_matching_pair_and_an_edit_that_leaves_the_pair_alone(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $next = $this->subscriptionIn($this->user->company);
        $workOrder = $this->workOrder($mine);

        $this->actingAs($this->user)
            ->put(route('admin.spk.update', $workOrder), ['title' => 'Judul baru'])
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHasNoErrors();

        $this->put(route('admin.spk.update', $workOrder), ['title' => 'Judul baru', 'customer_id' => $next->customer_id, 'subscription_id' => $next->id])
            ->assertRedirect(route('admin.spk.index'))
            ->assertSessionHasNoErrors();

        $fresh = $workOrder->fresh();
        $this->assertSame([$next->customer_id, $next->id, 'Judul baru'], [$fresh->customer_id, $fresh->subscription_id, $fresh->title]);
    }

    public function test_update_refuses_a_subscription_of_another_company_with_the_answer_of_an_unknown_one(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $foreign = $this->subscriptionIn($this->other);
        $workOrder = $this->workOrder($mine);

        foreach ([$foreign->id, $foreign->id + 10_000] as $subscriptionId) {
            $response = $this->actingAs($this->user)
                ->putJson(route('admin.spk.update', $workOrder), ['title' => 'Ganti', 'customer_id' => $mine->customer_id, 'subscription_id' => $subscriptionId])
                ->assertUnprocessable();

            $this->assertCount(1, $response->json('errors.subscription_id'));
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $type, ?int $customerId, ?int $subscriptionId): array
    {
        return ['type' => $type, 'title' => 'Pekerjaan '.$type, 'customer_id' => $customerId, 'subscription_id' => $subscriptionId];
    }

    private function workOrder(ServiceSubscription $subscription): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $subscription->company_id,
            'type' => 'maintenance',
            'status' => 'draft',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'created_by' => $this->user->id,
        ]);
    }
}
