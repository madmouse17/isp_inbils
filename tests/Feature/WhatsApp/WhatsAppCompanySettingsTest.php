<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppCompanySettingsTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true, 'settings' => ['tax_ppn_rate' => 11]]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin', $this->company);
    }

    /** @param array<string, mixed> $settings */
    private function save(array $settings): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.company.settings.update'), [
            'settings' => $settings,
            OptimisticLock::FIELD => OptimisticLock::version($this->company->fresh()),
        ]);
    }

    public function test_a_company_that_never_saved_gets_the_off_by_default_settings(): void
    {
        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Company/Settings')
            ->where('settings.whatsapp_enabled', false)
            ->where('settings.whatsapp_events', array_keys(config('whatsapp.events')))
            ->where('settings.whatsapp_quiet_start', '21:00')
            ->where('settings.whatsapp_quiet_end', '07:00')
            ->where('settings.whatsapp_monthly_cap', 1000)
            ->where('settings.tax_ppn_rate', 11)
            ->where('whatsapp.usage', ['sent' => 0, 'delivered' => 0, 'failed' => 0, 'billable' => 0]));
    }

    public function test_saved_values_replace_the_defaults(): void
    {
        $this->company->forceFill(['settings' => [
            'whatsapp_enabled' => true,
            'whatsapp_events' => ['payment_received'],
            'whatsapp_quiet_start' => '22:30',
            'whatsapp_quiet_end' => '06:00',
            'whatsapp_monthly_cap' => 250,
        ]])->save();

        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.whatsapp_enabled', true)
            ->where('settings.whatsapp_events', ['payment_received'])
            ->where('settings.whatsapp_quiet_start', '22:30')
            ->where('settings.whatsapp_quiet_end', '06:00')
            ->where('settings.whatsapp_monthly_cap', 250));
    }

    public function test_the_page_lists_the_events_to_switch_and_whether_credentials_exist_as_a_boolean_only(): void
    {
        $this->disableWhatsApp();
        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', false)
            ->has('whatsapp.events', count(config('whatsapp.events')))
            ->where('whatsapp.events.0', ['key' => 'invoice_issued', 'label' => 'Tagihan terbit', 'audience' => 'customer']));

        $this->enableWhatsApp();
        $response = $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', true));

        $json = json_encode($response->inertiaProps());
        foreach ([self::WA_TOKEN, self::WA_APP_SECRET, self::WA_VERIFY_TOKEN, self::WA_PHONE_NUMBER_ID, '102290129340398'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_whether_whatsapp_is_configured_is_per_company(): void
    {
        $rival = Company::factory()->create(['is_active' => true]);
        $rivalAdmin = User::factory()->create(['company_id' => $rival->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($rivalAdmin, 'admin', $rival);
        $this->whatsAppIntegration($this->company); // only this company has an account

        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', true));
        $this->actingAs($rivalAdmin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.configured', false));
    }

    public function test_usage_counts_this_months_rows_of_this_company_only(): void
    {
        $this->travelTo('2026-10-14 03:00:00'); // Jakarta: the month began at 2026-09-30 17:00 UTC
        $rival = Company::factory()->create();
        $make = fn (array $attributes = []) => WhatsAppMessage::factory()->create($attributes + ['company_id' => $this->company->id]);

        $make(['status' => 'sent', 'billable' => true]);
        $make(['status' => 'sent']);
        $make(['status' => 'delivered', 'billable' => true]);
        $make(['status' => 'read', 'billable' => false]);
        $make(['status' => 'failed', 'sent_at' => null, 'provider_message_id' => null]);
        $make(['status' => 'queued', 'sent_at' => null, 'provider_message_id' => null]);
        WhatsAppMessage::factory()->skipped('no_consent')->create(['company_id' => $this->company->id]);
        $make(['status' => 'sent', 'billable' => true, 'created_at' => '2026-09-30 16:59:59', 'sent_at' => '2026-09-30 16:59:59']); // September
        $make(['status' => 'sent', 'billable' => true, 'created_at' => '2026-09-30 16:55:00', 'sent_at' => '2026-09-30 17:05:00']); // created in September, sent in October
        WhatsAppMessage::factory()->create(['company_id' => $rival->id, 'billable' => true]);

        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.usage', ['sent' => 5, 'delivered' => 2, 'failed' => 1, 'billable' => 3]));
    }

    public function test_valid_settings_are_saved_next_to_the_existing_ones(): void
    {
        $this->save([
            'whatsapp_enabled' => true,
            'whatsapp_events' => ['invoice_issued', 'payment_received'],
            'whatsapp_quiet_start' => '20:00',
            'whatsapp_quiet_end' => '06:30',
            'whatsapp_monthly_cap' => 5000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = $this->company->fresh()->settings;
        $this->assertTrue($settings['whatsapp_enabled']);
        $this->assertSame(['invoice_issued', 'payment_received'], $settings['whatsapp_events']);
        $this->assertSame('20:00', $settings['whatsapp_quiet_start']);
        $this->assertSame('06:30', $settings['whatsapp_quiet_end']);
        $this->assertSame(5000, $settings['whatsapp_monthly_cap']);
        $this->assertSame(11, $settings['tax_ppn_rate'], 'unrelated settings survive');
    }

    public function test_every_event_and_the_edge_values_are_accepted(): void
    {
        $this->save([
            'whatsapp_enabled' => false,
            'whatsapp_events' => array_keys(config('whatsapp.events')),
            'whatsapp_quiet_start' => '00:00',
            'whatsapp_quiet_end' => '23:59',
            'whatsapp_monthly_cap' => 0,
        ])->assertSessionHasNoErrors();
        $this->save(['whatsapp_events' => [], 'whatsapp_monthly_cap' => 100000])->assertSessionHasNoErrors();

        $settings = $this->company->fresh()->settings;
        $this->assertSame([], $settings['whatsapp_events']);
        $this->assertSame(100000, $settings['whatsapp_monthly_cap']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidSettings(): array
    {
        return [
            'unknown event key' => [['whatsapp_events' => ['invoice_issued', 'free_pizza']], 'settings.whatsapp_events.1'],
            'event list is not a list of strings' => [['whatsapp_events' => [['invoice_issued']]], 'settings.whatsapp_events.0'],
            'events is not an array' => [['whatsapp_events' => 'invoice_issued'], 'settings.whatsapp_events'],
            'quiet start with seconds' => [['whatsapp_quiet_start' => '21:00:00'], 'settings.whatsapp_quiet_start'],
            'quiet start without a leading zero' => [['whatsapp_quiet_start' => '9:00'], 'settings.whatsapp_quiet_start'],
            'quiet end past midnight' => [['whatsapp_quiet_end' => '24:00'], 'settings.whatsapp_quiet_end'],
            'quiet end is not a time' => [['whatsapp_quiet_end' => 'morning'], 'settings.whatsapp_quiet_end'],
            'cap above the maximum' => [['whatsapp_monthly_cap' => 100001], 'settings.whatsapp_monthly_cap'],
            'negative cap' => [['whatsapp_monthly_cap' => -1], 'settings.whatsapp_monthly_cap'],
            'fractional cap' => [['whatsapp_monthly_cap' => 10.5], 'settings.whatsapp_monthly_cap'],
            'text cap' => [['whatsapp_monthly_cap' => 'lots'], 'settings.whatsapp_monthly_cap'],
            'switch is not a boolean' => [['whatsapp_enabled' => 'yes please'], 'settings.whatsapp_enabled'],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidSettings')]
    public function test_invalid_whatsapp_settings_are_rejected_and_nothing_is_saved(array $settings, string $errorKey): void
    {
        $this->save($settings)->assertInvalid([$errorKey]);

        $this->assertSame(['tax_ppn_rate' => 11], $this->company->fresh()->settings);
    }

    public function test_only_company_managers_may_see_or_change_the_settings(): void
    {
        $role = $this->companyRole($this->company, 'viewer', ['app.web', 'customer.view']);
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($viewer, $role, $this->company);

        $this->actingAs($viewer)->get(route('admin.company.settings.edit'))->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.company.settings.update'), ['settings' => ['whatsapp_enabled' => true]])->assertForbidden();

        $this->assertSame(['tax_ppn_rate' => 11], $this->company->fresh()->settings);
    }

    public function test_one_company_cannot_change_anothers_settings(): void
    {
        $rival = Company::factory()->create(['settings' => ['whatsapp_enabled' => false]]);

        $this->save(['whatsapp_enabled' => true])->assertRedirect();

        $this->assertFalse($rival->fresh()->settings['whatsapp_enabled']);
        $this->assertTrue($this->company->fresh()->settings['whatsapp_enabled']);
    }
}
