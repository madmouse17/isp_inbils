<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Services\Core\SetupWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** MySQL commits on DDL: the test puts the dropped columns back or forward before it ends. */
class SlaCleanupMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uptime_and_credit_columns_drop_and_return_nullable(): void
    {
        $migration = require base_path('Modules/Service/database/migrations/2026_10_14_100000_drop_uptime_and_credit_from_sla_tiers_table.php');

        try {
            $this->assertFalse(Schema::hasColumn('sla_tiers', 'uptime_pct'));
            $this->assertFalse(Schema::hasColumn('sla_tiers', 'credit_pct'));

            $migration->down();
            $this->assertTrue(Schema::hasColumn('sla_tiers', 'uptime_pct'));
            $this->assertTrue(Schema::hasColumn('sla_tiers', 'credit_pct'));
            $columns = collect(Schema::getColumns('sla_tiers'))->keyBy('name');
            $this->assertTrue($columns['uptime_pct']['nullable']);
            $this->assertTrue($columns['credit_pct']['nullable']);

            $migration->up();
            $this->assertFalse(Schema::hasColumn('sla_tiers', 'uptime_pct'));
            $this->assertFalse(Schema::hasColumn('sla_tiers', 'credit_pct'));
        } finally {
            if (Schema::hasColumn('sla_tiers', 'uptime_pct') || Schema::hasColumn('sla_tiers', 'credit_pct')) {
                $migration->up();
            }
        }
    }

    public function test_dead_ticket_settings_are_removed_from_every_company_and_other_keys_stay(): void
    {
        $migration = require database_path('migrations/2026_10_14_100001_remove_dead_ticket_sla_settings_from_companies.php');
        $dirty = Company::factory()->create(['settings' => ['ticket_default_sla_hours' => 24, 'ticket_sla_mode' => 'calendar', 'ticket_prefix' => 'TKT', 'tax_enabled' => true]]);
        $partial = Company::factory()->create(['settings' => ['ticket_sla_mode' => 'business', 'invoice_prefix' => 'INV']]);
        $clean = Company::factory()->create(['settings' => ['ticket_prefix' => 'TKT']]);
        $none = Company::factory()->create(['settings' => null]);

        $migration->up();

        $this->assertEquals(['ticket_prefix' => 'TKT', 'tax_enabled' => true], $dirty->fresh()->settings);
        $this->assertSame(['invoice_prefix' => 'INV'], $partial->fresh()->settings);
        $this->assertSame(['ticket_prefix' => 'TKT'], $clean->fresh()->settings);
        $this->assertNull($none->fresh()->settings);

        $migration->down();
        $this->assertEquals(['ticket_prefix' => 'TKT', 'tax_enabled' => true], $dirty->fresh()->settings);
    }

    public function test_new_companies_no_longer_get_the_dead_settings(): void
    {
        $defaults = SetupWizardService::defaultSettings('IDR', 'd/m/Y', 'd/m/Y H:i');

        $this->assertArrayNotHasKey('ticket_default_sla_hours', $defaults);
        $this->assertArrayNotHasKey('ticket_sla_mode', $defaults);
        $this->assertArrayHasKey('ticket_prefix', $defaults);
    }
}
