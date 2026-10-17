<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * An invoice names a customer and may name one of that customer's subscriptions. The pair is checked on the server,
 * and the create form is offered only the chosen customer's subscriptions.
 */
class InvoiceSubscriptionPairTest extends TestCase
{
    use AsksInertiaForProps;
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

    public function test_store_keeps_the_subscription_of_the_chosen_customer(): void
    {
        $subscription = $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->post(route('admin.invoices.store'), ['customer_id' => $subscription->customer->public_id, 'subscription_id' => $subscription->public_id])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invoices', ['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id]);
    }

    public function test_store_refuses_a_subscription_of_another_customer_of_the_company(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $theirs = $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.store'), ['customer_id' => $mine->customer->public_id, 'subscription_id' => $theirs->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_refuses_a_soft_deleted_subscription_of_the_customer(): void
    {
        $subscription = $this->subscriptionIn($this->user->company);
        $subscription->delete();

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.store'), ['customer_id' => $subscription->customer->public_id, 'subscription_id' => $subscription->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_subscription_of_another_company_and_an_unknown_one_get_the_same_single_answer(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $foreign = $this->subscriptionIn($this->other);

        foreach ([$foreign->public_id, PublicId::new(), (string) $foreign->id] as $subscriptionId) {
            $this->actingAs($this->user)
                ->postJson(route('admin.invoices.store'), ['customer_id' => $mine->customer->public_id, 'subscription_id' => $subscriptionId])
                ->assertUnprocessable()
                ->assertJsonPath('errors.subscription_id', ['Langganan yang dipilih tidak ditemukan.']);
        }
    }

    public function test_create_offers_only_the_subscriptions_of_the_chosen_customer(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $this->subscriptionIn($this->user->company);

        $this->actingAs($this->user)
            ->get(
                route('admin.invoices.create', ['customer_id' => $mine->customer->public_id, 'subscription_search' => 'SUB-']),
                $this->partialReload('Admin/Billing/Invoices/Create', 'subscriptionOptions'),
            )
            ->assertOk()
            ->assertJsonPath('props.subscriptionOptions', [
                ['value' => $mine->public_id, 'label' => $mine->code, 'description' => $mine->customer->name],
            ]);
    }

    public function test_create_offers_the_subscriptions_of_the_company_while_no_customer_is_chosen(): void
    {
        $this->subscriptionIn($this->user->company);
        $this->subscriptionIn($this->user->company);
        $this->subscriptionIn($this->other);

        $this->actingAs($this->user)
            ->get(
                route('admin.invoices.create', ['subscription_search' => 'SUB-']),
                $this->partialReload('Admin/Billing/Invoices/Create', 'subscriptionOptions'),
            )
            ->assertOk()
            ->assertJsonCount(2, 'props.subscriptionOptions');
    }
}
