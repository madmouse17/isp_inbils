<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class BillingCommandsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_generate_command_creates_invoices_for_given_period(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
        auth()->logout();

        $this->artisan('billing:generate', ['--period' => '2026-06'])
            ->expectsOutputToContain('created: 1')
            ->assertExitCode(0);

        $this->assertSame(1, Invoice::withoutCompany()->where('type', 'recurring')->count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
        auth()->logout();

        $this->artisan('billing:generate', ['--period' => '2026-06', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::withoutCompany()->count());
    }

    public function test_check_overdue_command_runs(): void
    {
        $this->artisan('billing:check-overdue')->assertExitCode(0);
    }
}
