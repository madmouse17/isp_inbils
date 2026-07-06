<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class GenerateEndpointTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

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
}
