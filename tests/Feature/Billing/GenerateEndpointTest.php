<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class GenerateEndpointTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private function makeActiveSub(): void
    {
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
    }

    public function test_preview_returns_rows_without_creating(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeActiveSub();

        $response = $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-06']);

        $response->assertOk()->assertJsonCount(1, 'rows');
        $this->assertSame(0, Invoice::count());
    }

    public function test_generate_creates_invoices(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeActiveSub();

        $this->post(route('admin.invoices.generate'), ['period' => '2026-06'])
            ->assertRedirect();

        $this->assertSame(1, Invoice::where('type', 'recurring')->count());
    }

    public function test_period_is_validated(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->postJson(route('admin.invoices.generate-preview'), ['period' => 'nope'])
            ->assertUnprocessable();
    }

    public function test_future_period_is_refused(): void
    {
        $user = $this->createCompanyUser();
        $user->company->update(['timezone' => 'Asia/Jakarta']);
        $this->actingAs($user);
        // 18:00 UTC on 31 October is already 1 November in Jakarta: the company's month in progress is November.
        $this->travelTo('2026-10-31 18:00:00');
        $this->makeActiveSub();

        foreach (['admin.invoices.generate-preview', 'admin.invoices.generate'] as $route) {
            $this->postJson(route($route), ['period' => '2026-12'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.period.0', 'Periode tagihan paling lambat bulan berjalan.');
        }
        $this->assertSame(0, Invoice::count());

        $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-11'])->assertOk()->assertJsonPath('is_current_period', true);
        $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-10'])->assertOk()->assertJsonPath('is_current_period', false);
    }

    public function test_preview_lists_skipped_with_reasons(): void
    {
        $this->actingAs($this->createCompanyUser());
        $issued = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-05-10', 'mrc_amount' => 300000]);
        $free = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-05-10', 'mrc_amount' => 0]);
        BillingService::generateForPeriod('2026-06');
        $waiting = ServiceSubscription::factory()->create(['status' => 'pending', 'activation_date' => null]);
        $later = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-07-05']);
        $isolated = ServiceSubscription::factory()->create(['status' => 'suspended', 'activation_date' => '2026-05-10']);
        ServiceSubscription::factory()->create(['status' => 'terminated', 'activation_date' => '2026-01-10', 'terminated_at' => '2026-05-20 00:00:00']);
        $billable = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-05-10', 'mrc_amount' => 150000]);

        $response = $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-06'])->assertOk();

        $response->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.subscription_code', $billable->code);
        $this->assertSame(5, $response->json('skipped'));
        $this->assertEquals([
            $issued->code => ['already_issued', 'Tagihan periode ini sudah ada'],
            $free->code => ['zero_amount', 'Nominal Rp 0'],
            $waiting->code => ['not_active', 'Belum aktif pada periode ini'],
            $later->code => ['not_active', 'Belum aktif pada periode ini'],
            $isolated->code => ['suspended', 'Ditangguhkan'],
        ], collect($response->json('skipped_rows'))->mapWithKeys(fn (array $row): array => [$row['subscription_code'] => [$row['reason'], $row['reason_label']]])->all());
        $this->assertSame(['subscription_code', 'customer', 'reason', 'reason_label'], array_keys($response->json('skipped_rows.0')));
    }

    public function test_the_preview_names_at_most_fifty_skipped_subscriptions_and_counts_them_all(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->count(52)->create(['status' => 'pending', 'activation_date' => null]);

        $response = $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-06'])->assertOk();

        $this->assertSame(52, $response->json('skipped'));
        $response->assertJsonCount(50, 'skipped_rows');
    }
}
