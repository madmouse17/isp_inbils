<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppConsentTest extends TestCase
{
    use AllowsManualCodes;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->setManualCodes($this->company->id, true, 'customer');
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function updatePayload(Customer $customer, array $overrides = []): array
    {
        return [
            'code' => $customer->code,
            'name' => $customer->name,
            'type' => $customer->type,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function consentAudits(Customer $customer): Collection
    {
        return Activity::query()->where('log_name', 'whatsapp')->where('subject_type', Customer::class)->where('subject_id', $customer->id)->get();
    }

    public function test_onboarding_with_the_checkbox_records_who_agreed_and_when(): void
    {
        $this->travelTo('2026-10-14 03:00:00');

        $this->actingAs($this->admin)->post(route('admin.customers.store'), $this->onboardingPayload(['whatsapp_opt_in' => true]))->assertRedirect();

        $customer = Customer::query()->where('code', 'CUS-WA')->firstOrFail();
        $this->assertTrue($customer->whatsapp_opt_in_at->equalTo('2026-10-14 03:00:00'));
        $this->assertSame('staff', $customer->whatsapp_opt_in_source);
        $this->assertSame($this->admin->id, $customer->whatsapp_opt_in_by);
        $this->assertNull($customer->whatsapp_opt_out_at);
        $this->assertTrue($customer->hasWhatsAppConsent());
    }

    public function test_onboarding_without_the_checkbox_or_with_it_unticked_records_nothing(): void
    {
        $this->actingAs($this->admin)->post(route('admin.customers.store'), $this->onboardingPayload())->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.customers.store'), $this->onboardingPayload(['code' => 'CUS-WA2', 'email' => 'wa2@example.test', 'whatsapp_opt_in' => false]))->assertRedirect();

        foreach (['CUS-WA', 'CUS-WA2'] as $code) {
            $customer = Customer::query()->where('code', $code)->firstOrFail();
            $this->assertNull($customer->whatsapp_opt_in_at);
            $this->assertNull($customer->whatsapp_opt_in_source);
            $this->assertNull($customer->whatsapp_opt_in_by);
            $this->assertNull($customer->whatsapp_opt_out_at);
            $this->assertCount(0, $this->consentAudits($customer));
        }
    }

    public function test_the_checkbox_must_be_a_boolean(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->onboardingPayload(['whatsapp_opt_in' => 'maybe']))
            ->assertInvalid(['whatsapp_opt_in']);
        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $this->plainCustomer()), $this->updatePayload($this->plainCustomer(), ['whatsapp_opt_in' => 'maybe']))
            ->assertInvalid(['whatsapp_opt_in']);
    }

    private function plainCustomer(): Customer
    {
        return Customer::query()->where('code', 'CUS-PLAIN')->first()
            ?? Customer::factory()->create(['company_id' => $this->company->id, 'code' => 'CUS-PLAIN', 'phone' => '0812-3456-7890', 'email' => 'plain@example.test', 'type' => 'Individual']);
    }

    public function test_unticking_withdraws_consent_and_reticking_gives_a_fresh_consent(): void
    {
        $customer = $this->consentedCustomer($this->company);
        $firstConsent = $customer->whatsapp_opt_in_at;

        $this->travelTo(now()->addDay());
        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['whatsapp_opt_in' => false]))->assertRedirect();

        $customer->refresh();
        $this->assertNotNull($customer->whatsapp_opt_out_at);
        $this->assertFalse($customer->hasWhatsAppConsent());
        $this->assertTrue($customer->whatsapp_opt_in_at->equalTo($firstConsent), 'the original consent stays on record');

        $this->travelTo(now()->addDay());
        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['whatsapp_opt_in' => true]))->assertRedirect();

        $customer->refresh();
        $this->assertNull($customer->whatsapp_opt_out_at);
        $this->assertTrue($customer->whatsapp_opt_in_at->greaterThan($firstConsent));
        $this->assertSame($this->admin->id, $customer->whatsapp_opt_in_by);
        $this->assertTrue($customer->hasWhatsAppConsent());
    }

    public function test_ticking_after_a_stop_reply_clears_the_opt_out(): void
    {
        $customer = $this->consentedCustomer($this->company);
        $customer->forceFill(['whatsapp_opt_out_at' => now()])->save();

        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['whatsapp_opt_in' => true]))->assertRedirect();

        $this->assertTrue($customer->refresh()->hasWhatsAppConsent());
    }

    public function test_an_unchanged_value_writes_nothing_and_audits_nothing(): void
    {
        $consented = $this->consentedCustomer($this->company);
        $never = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '0812-3456-7891']);
        $withdrawn = $this->consentedCustomer($this->company, '0812-3456-7892');
        $withdrawn->forceFill(['whatsapp_opt_out_at' => now()->subDay()])->save();
        $before = [$consented->fresh()->only(self::columns()), $never->fresh()->only(self::columns()), $withdrawn->fresh()->only(self::columns())];

        $this->travelTo(now()->addDays(2));
        $this->actingAs($this->admin);
        $this->put(route('admin.customers.update', $consented), $this->updatePayload($consented, ['whatsapp_opt_in' => true]))->assertRedirect();
        $this->put(route('admin.customers.update', $never), $this->updatePayload($never, ['whatsapp_opt_in' => false]))->assertRedirect();
        $this->put(route('admin.customers.update', $withdrawn), $this->updatePayload($withdrawn, ['whatsapp_opt_in' => false]))->assertRedirect();

        $this->assertEquals($before, [$consented->fresh()->only(self::columns()), $never->fresh()->only(self::columns()), $withdrawn->fresh()->only(self::columns())]);
        $this->assertSame(0, Activity::query()->where('log_name', 'whatsapp')->count());
    }

    public function test_leaving_the_checkbox_out_of_an_update_keeps_the_consent(): void
    {
        $customer = $this->consentedCustomer($this->company);

        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['name' => 'Renamed']))->assertRedirect();

        $this->assertTrue($customer->refresh()->hasWhatsAppConsent());
        $this->assertSame('Renamed', $customer->name);
    }

    public function test_consent_changes_are_audited_with_ids_only(): void
    {
        $customer = $this->consentedCustomer($this->company);

        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['whatsapp_opt_in' => false]))->assertRedirect();

        $audit = $this->consentAudits($customer)->sole();
        $this->assertSame('opted_out', $audit->description);
        $this->assertSame($this->company->id, (int) $audit->company_id);
        $this->assertEquals(['customer_id' => $customer->id, 'actor_id' => $this->admin->id, 'source' => 'staff', 'company_id' => $this->company->id], $audit->properties->toArray());
        $this->assertStringNotContainsString('0812', $audit->properties->toJson());
        $this->assertSame($this->admin->id, (int) $audit->causer_id);
    }

    public function test_a_user_without_customer_update_cannot_change_consent(): void
    {
        $customer = $this->consentedCustomer($this->company);
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $role = Role::create(['name' => 'customer-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('customer.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['whatsapp_opt_in' => false]))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.customers.store'), $this->onboardingPayload(['whatsapp_opt_in' => true]))->assertForbidden();

        $this->assertTrue($customer->refresh()->hasWhatsAppConsent());
        $this->assertDatabaseMissing('customers', ['code' => 'CUS-WA']);
    }

    public function test_consent_columns_cannot_be_written_through_any_other_field(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '0812-3456-7890']);
        $forged = [
            'whatsapp_opt_in_at' => '2020-01-01 00:00:00',
            'whatsapp_opt_in_source' => 'import',
            'whatsapp_opt_in_by' => $this->admin->id,
            'whatsapp_opt_out_at' => '2020-01-01 00:00:00',
        ];

        $this->actingAs($this->admin)->put(route('admin.customers.update', $customer), $this->updatePayload($customer, $forged))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.customers.store'), $this->onboardingPayload($forged))->assertRedirect();

        $this->assertSame([null, null, null, null], array_values($customer->fresh()->only(self::columns())));
        $created = Customer::query()->where('code', 'CUS-WA')->firstOrFail();
        $this->assertSame([null, null, null, null], array_values($created->only(self::columns())));
        $this->assertFalse(in_array('whatsapp_opt_in_at', (new Customer())->getFillable(), true));
    }

    /** @return list<string> */
    private static function columns(): array
    {
        return ['whatsapp_opt_in_at', 'whatsapp_opt_in_source', 'whatsapp_opt_in_by', 'whatsapp_opt_out_at'];
    }

    public function test_show_and_edit_carry_the_consent_state_and_phone_reachability(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $landline = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '021-5551234']);

        foreach (['admin.customers.show', 'admin.customers.edit'] as $route) {
            $this->actingAs($this->admin)->get(route($route, $customer))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('customer.data.whatsapp.opted_in', true)
                ->where('customer.data.whatsapp.opted_in_at', $customer->whatsapp_opt_in_at->toJSON())
                ->where('customer.data.whatsapp.opted_out_at', null)
                ->where('customer.data.whatsapp.reachable', true));
        }

        $customer->forceFill(['whatsapp_opt_out_at' => now()])->save();
        $this->actingAs($this->admin)->get(route('admin.customers.show', $customer))->assertInertia(fn (Assert $page) => $page
            ->where('customer.data.whatsapp.opted_in', false)
            ->whereNot('customer.data.whatsapp.opted_out_at', null));

        $this->actingAs($this->admin)->get(route('admin.customers.show', $landline))->assertInertia(fn (Assert $page) => $page
            ->where('customer.data.whatsapp.opted_in', false)
            ->where('customer.data.whatsapp.opted_in_at', null)
            ->where('customer.data.whatsapp.reachable', false));
    }

    public function test_show_lists_at_most_twenty_message_rows_of_this_customer_only_newest_first_without_the_phone(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $other = $this->consentedCustomer($this->company, '0812-3456-7891');
        $rival = Company::factory()->create();
        foreach (range(1, 22) as $i) {
            WhatsAppMessage::factory()->create([
                'company_id' => $this->company->id,
                'recipient_id' => $customer->id,
                'created_at' => now()->subMinutes(30 - $i),
                'recipient_last4' => '7890',
            ]);
        }
        WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'recipient_id' => $other->id]);
        WhatsAppMessage::factory()->create(['company_id' => $this->company->id, 'recipient_type' => 'user', 'recipient_id' => $customer->id]);
        WhatsAppMessage::factory()->create(['company_id' => $rival->id, 'recipient_id' => $customer->id]);
        $skipped = WhatsAppMessage::factory()->skipped('no_consent')->create(['company_id' => $this->company->id, 'recipient_id' => $customer->id, 'created_at' => now()]);

        $response = $this->actingAs($this->admin)->get(route('admin.customers.show', $customer))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('whatsappMessages', 20)
            ->where('whatsappMessages.0.id', $skipped->id)
            ->where('whatsappMessages.0.status', 'skipped')
            ->where('whatsappMessages.0.skip_reason', 'no_consent')
            ->has('whatsappMessages.1', fn (AssertableJson $row) => $row
                ->hasAll(['id', 'event', 'subject_type', 'subject_id', 'status', 'skip_reason', 'error_code', 'recipient_last4', 'created_at', 'delivered_at', 'read_at', 'failed_at'])
                ->where('recipient_last4', '7890')));

        $rows = collect($response->inertiaProps('whatsappMessages'));
        $stored = WhatsAppMessage::withoutCompany()->whereKey($rows->pluck('id'))->get();
        $this->assertSame([$this->company->id], $stored->pluck('company_id')->unique()->values()->all());
        $this->assertSame(['customer'], $stored->pluck('recipient_type')->unique()->values()->all());
        $this->assertSame([$customer->id], $stored->pluck('recipient_id')->unique()->values()->all());
        $json = json_encode([$rows, $response->inertiaProps('customer.data.whatsapp')]);
        foreach (['6281234567890', '081234567890', '0812-3456-7890', 'recipient_hash', 'dedupe_key', 'provider_message_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_a_company_never_sees_another_companys_message_rows(): void
    {
        $rival = Company::factory()->create();
        $rivalCustomer = $this->consentedCustomer($rival);
        $mine = $this->consentedCustomer($this->company);
        WhatsAppMessage::factory()->create(['company_id' => $rival->id, 'recipient_id' => $mine->id]);

        $this->actingAs($this->admin)->get(route('admin.customers.show', $mine))->assertInertia(fn (Assert $page) => $page->has('whatsappMessages', 0));
        $this->actingAs($this->admin)->get(route('admin.customers.show', $rivalCustomer))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function onboardingPayload(array $overrides = []): array
    {
        return $this->customerOnboardingPayload($this->company, $overrides);
    }
}
