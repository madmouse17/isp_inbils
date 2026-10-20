<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\PaymentAttempt;
use Tests\TestCase;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * The migration behind "Dikembalikan", rolled back and applied again. MySQL commits on DDL, so the test commits its
 * fixture first and removes it with purgeCompany(); it always puts the columns and the enum value back.
 */
class PaymentAttemptRefundMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const FILE = 'Modules/Billing/database/migrations/2026_10_28_100000_add_refund_resolution_to_payment_attempts_table.php';

    private const COLUMNS = ['resolved_by', 'resolved_at', 'resolution_reference', 'resolution_note'];

    private int $companyId;

    private PaymentAttempt $marked;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create(['is_active' => true]);
        $this->companyId = $company->id;
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $company->id,
            'customer_id' => Customer::factory()->create(['company_id' => $company->id])->id,
            'status' => 'sent',
            'total' => 100000,
        ]);
        $this->marked = PaymentAttemptFactory::new()->forInvoice($invoice)->status(PaymentAttempt::STATUS_REFUNDED)->create([
            'resolved_at' => now(),
            'resolution_reference' => 'MID-RFD-0001',
            'resolution_note' => 'Dikembalikan lewat dashboard Midtrans.',
        ]);
        $this->commitFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeCompany($this->companyId);

        parent::tearDown();
    }

    private function statusColumnType(): string
    {
        return (string) collect(Schema::getColumns('payment_attempts'))->firstWhere('name', 'status')['type'];
    }

    public function test_it_rolls_back_to_the_old_table_with_the_money_needing_a_person_again_and_applies_again(): void
    {
        $migration = require base_path(self::FILE);

        try {
            $this->assertTrue(Schema::hasColumns('payment_attempts', self::COLUMNS));
            $this->assertStringContainsString("'refunded'", $this->statusColumnType());

            $migration->down();

            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn('payment_attempts', $column), $column);
            }
            $this->assertStringNotContainsString('refunded', $this->statusColumnType());
            $this->assertSame('exception', DB::table('payment_attempts')->where('id', $this->marked->id)->value('status'), 'a marked attempt is "Perlu dicek" again');
            $this->assertSame([], array_values(array_filter(Schema::getForeignKeys('payment_attempts'), fn (array $key): bool => $key['columns'] === ['resolved_by'])), 'the foreign key goes with the column');
        } finally {
            if (! Schema::hasColumn('payment_attempts', 'resolved_by')) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasColumns('payment_attempts', self::COLUMNS));
        $this->assertStringContainsString("'refunded'", $this->statusColumnType());
        $key = collect(Schema::getForeignKeys('payment_attempts'))->first(fn (array $key): bool => $key['columns'] === ['resolved_by']);
        $this->assertSame(['users', 'set null'], [$key['foreign_table'], $key['on_delete']], 'a deleted user leaves the mark and loses only the name');
    }
}
