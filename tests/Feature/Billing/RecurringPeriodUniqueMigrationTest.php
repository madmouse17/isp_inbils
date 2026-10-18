<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * The migration that backs "one active recurring invoice per subscription and period", rolled back and applied again.
 * MySQL commits on DDL, so each test commits its fixture first and removes it with purgeCompany(); every test puts the
 * column and the index back.
 */
class RecurringPeriodUniqueMigrationTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const FILE = 'Modules/Billing/database/migrations/2026_10_23_120100_add_active_recurring_period_to_invoices_table.php';

    private const COLUMN = 'active_recurring_period';

    private const INDEX = 'invoices_active_recurring_period_unique';

    private int $companyId;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create(['is_active' => true]);
        $this->companyId = $company->id;
        $this->subscription = $this->subscriptionIn($company);
        $this->commitFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeCompany($this->companyId);

        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(string $number, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $this->companyId,
            'customer_id' => $this->subscription->customer_id,
            'subscription_id' => $this->subscription->id,
            'created_by' => null,
            'number' => $number,
            'type' => 'recurring',
            'source' => 'subscription',
            'status' => 'sent',
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            ...$attributes,
        ]);
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    public function test_it_stops_before_any_change_when_active_duplicates_exist(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();

            $this->invoice('INV-DUP-1');
            $this->invoice('INV-DUP-2');
            $this->invoice('INV-DUP-CANCELLED', ['status' => 'cancelled']);
            $this->invoice('INV-ONE-TIME', ['type' => 'one_time', 'source' => 'manual']);

            try {
                $migration->up();
                $this->fail('Two active recurring invoices of one period must stop the migration.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('INV-DUP-1,INV-DUP-2', $e->getMessage(), 'it names the invoices a person has to settle');
                $this->assertStringNotContainsString('INV-DUP-CANCELLED', $e->getMessage());
                $this->assertStringNotContainsString('INV-ONE-TIME', $e->getMessage());
            }

            $this->assertFalse(Schema::hasColumn('invoices', self::COLUMN), 'the check runs before any DDL');
            $this->assertFalse(Schema::hasIndex('invoices', self::INDEX));
        } finally {
            DB::table('invoices')->where('company_id', $this->companyId)->delete();
            $migration->up();
        }
    }

    public function test_it_fills_the_period_of_existing_recurring_rows(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();

            $this->invoice('INV-ACTIVE');
            $this->invoice('INV-CANCELLED', ['status' => 'cancelled']);
            $this->invoice('INV-TRASHED', ['billing_period_start' => '2026-05-01'])->delete();
            $this->invoice('INV-ONE-TIME', ['type' => 'one_time', 'source' => 'manual']);
            // No period, so nothing to collide on: several of them for one subscription must not stop the migration.
            $this->invoice('INV-NO-PERIOD-1', ['billing_period_start' => null, 'billing_period_end' => null]);
            $this->invoice('INV-NO-PERIOD-2', ['billing_period_start' => null, 'billing_period_end' => null]);

            $migration->up();

            $this->assertSame([
                'INV-ACTIVE' => '2026-06-01',
                'INV-CANCELLED' => null,
                'INV-TRASHED' => null,
                'INV-ONE-TIME' => null,
                'INV-NO-PERIOD-1' => null,
                'INV-NO-PERIOD-2' => null,
            ], DB::table('invoices')->where('company_id', $this->companyId)->orderBy('id')->pluck(self::COLUMN, 'number')->all());
            $this->assertTrue(Schema::hasIndex('invoices', self::INDEX, 'unique'));
        } finally {
            $migration->up();
        }
    }

    public function test_it_rolls_back_to_the_old_table_and_applies_again_without_complaint(): void
    {
        $migration = $this->migration();

        try {
            $this->assertTrue(Schema::hasColumn('invoices', self::COLUMN));
            $this->assertTrue(Schema::hasIndex('invoices', self::INDEX, 'unique'));

            $migration->down();

            $this->assertFalse(Schema::hasColumn('invoices', self::COLUMN));
            $this->assertFalse(Schema::hasIndex('invoices', self::INDEX));
            $this->assertSame([], array_values(array_filter(Schema::getIndexes('invoices'), fn (array $index): bool => in_array(self::COLUMN, $index['columns'], true))), 'the index goes first, or dropping the column would leave a narrower unique key behind');

            $migration->up();
            $migration->up();

            $this->assertTrue(Schema::hasColumn('invoices', self::COLUMN));
            $this->assertTrue(Schema::hasIndex('invoices', self::INDEX, 'unique'));
        } finally {
            $migration->up();
        }
    }
}
