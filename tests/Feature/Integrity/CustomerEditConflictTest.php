<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\OptimisticLock;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * Item 11 of integrity-hardening for the customer and subscription forms: a save carries the version the form was
 * rendered from; a save from an older copy is refused and writes nothing, not even the consent or the package side
 * effects that ride along with the row.
 */
class CustomerEditConflictTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
    }

    /** A customer last saved five minutes ago, so a save made now is a different version of it (versions keep whole seconds). */
    private function customer(): Customer
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'as rendered']);
        DB::table('customers')->where('id', $customer->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $customer->fresh();
    }

    /** A pending subscription (its SPK can still be edited) last saved five minutes ago. */
    private function subscription(): ServiceSubscription
    {
        $subscription = $this->subscriptionIn($this->admin->company, 'pending');
        DB::table('service_subscriptions')->where('id', $subscription->id)->update(['updated_at' => now()->subMinutes(5), 'notes' => 'as rendered']);

        return $subscription->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, array $overrides): array
    {
        return ['code' => $customer->code, 'name' => 'Renamed', 'type' => $customer->type, 'is_active' => true, ...$overrides];
    }

    public function test_a_save_from_an_older_copy_is_refused_and_changes_nothing(): void
    {
        $customer = $this->customer();
        $url = route('admin.customers.update', $customer);
        $rendered = OptimisticLock::version($customer);

        $this->actingAs($this->admin)
            ->put($url, $this->payload($customer, ['name' => 'First save', OptimisticLock::FIELD => $rendered]))
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success', 'Pelanggan diperbarui.');

        // The refused copy also ticks the WhatsApp consent: that write rides in the same transaction and must not land.
        $stale = $this->payload($customer, ['name' => 'Second save', 'whatsapp_opt_in' => true, OptimisticLock::FIELD => $rendered]);

        $this->putJson($url, $stale)
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);

        // A page visit (X-Inertia) gets the redirect back with the message the toast and the banner show.
        $this->withHeaders(['X-Inertia' => 'true'])
            ->put($url, $stale)
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);

        $saved = $customer->fresh();
        $this->assertSame('First save', $saved->name, 'the refused saves changed nothing');
        $this->assertNull($saved->whatsapp_opt_in_at, 'the refused saves recorded no consent either');
        $this->assertSame(0, Activity::query()->where('log_name', 'whatsapp')->count());
    }

    public function test_a_subscription_save_from_an_older_copy_is_refused(): void
    {
        $subscription = $this->subscription();
        $url = route('admin.subscriptions.update', $subscription);
        $rendered = OptimisticLock::version($subscription);

        $this->actingAs($this->admin)
            ->put($url, ['notes' => 'first save', OptimisticLock::FIELD => $rendered])
            ->assertRedirect()
            ->assertSessionHas('success', 'Langganan diperbarui.');

        $stale = ['notes' => 'second save', 'billing_day' => 9, OptimisticLock::FIELD => $rendered];

        $this->putJson($url, $stale)
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);

        $this->withHeaders(['X-Inertia' => 'true'])
            ->put($url, $stale)
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);

        $saved = $this->freshSubscription($subscription);
        $this->assertSame('first save', $saved->notes, 'the refused saves changed nothing');
        $this->assertSame($subscription->billing_day, $saved->billing_day);
    }

    public function test_a_save_from_the_current_copy_is_stored(): void
    {
        $customer = $this->customer();
        $subscription = $this->subscription();

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), $this->payload($customer, ['name' => 'Renamed', OptimisticLock::FIELD => OptimisticLock::version($customer)]))
            ->assertRedirect(route('admin.customers.index'));
        $this->put(route('admin.subscriptions.update', $subscription), ['notes' => 'saved', OptimisticLock::FIELD => OptimisticLock::version($subscription)])
            ->assertRedirect();

        $this->assertSame('Renamed', $customer->fresh()->name);
        $this->assertSame('saved', $this->freshSubscription($subscription)->notes);
    }

    public function test_a_save_needs_the_version_the_form_was_rendered_from(): void
    {
        $customer = $this->customer();
        $subscription = $this->subscription();

        $this->actingAs($this->admin)
            ->putJson(route('admin.customers.update', $customer), $this->payload($customer, ['name' => 'No version']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);
        $this->putJson(route('admin.subscriptions.update', $subscription), ['notes' => 'No version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);
        $this->putJson(route('admin.subscriptions.update', $subscription), ['notes' => 'Bad version', OptimisticLock::FIELD => '2026-10-18'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('as rendered', $customer->fresh()->name);
        $this->assertSame('as rendered', $this->freshSubscription($subscription)->notes);
    }

    public function test_the_edit_page_hands_out_the_version_to_send_back(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin)->get(route('admin.customers.edit', $customer))
            ->assertInertia(fn (Assert $page) => $page->where('customer.data.updated_at', OptimisticLock::version($customer)));
    }

    public function test_a_save_locks_its_row_before_it_writes_it(): void
    {
        $customer = $this->customer();
        $subscription = $this->subscription();
        $this->actingAs($this->admin);

        $this->assertLockedBeforeWritten('customers', fn () => $this->put(
            route('admin.customers.update', $customer),
            $this->payload($customer, ['name' => 'Locked', OptimisticLock::FIELD => OptimisticLock::version($customer)]),
        )->assertRedirect(route('admin.customers.index')));
        $this->assertLockedBeforeWritten('service_subscriptions', fn () => $this->put(
            route('admin.subscriptions.update', $subscription),
            ['notes' => 'locked', OptimisticLock::FIELD => OptimisticLock::version($subscription)],
        )->assertRedirect());
    }

    /**
     * The compare of the version is only as good as the read it looks at: the row has to be read FOR UPDATE, before
     * the first write, or two saves from one copy both pass the compare.
     */
    private function assertLockedBeforeWritten(string $table, Closure $request): void
    {
        DB::enableQueryLog();

        try {
            $request();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        $lock = $write = null;

        foreach ($queries as $index => $sql) {
            $sql = trim($sql);
            $lock ??= str_contains($sql, "from `{$table}`") && str_ends_with($sql, 'for update') ? $index : null;
            $write ??= str_starts_with($sql, "update `{$table}`") ? $index : null;
        }

        $this->assertNotNull($lock, "{$table} is read FOR UPDATE");
        $this->assertNotNull($write, "{$table} is written");
        $this->assertLessThan($write, $lock, "{$table} is locked before it is written");
    }
}
