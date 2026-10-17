<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class PaymentGatewaySchemaTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
    }

    private function invoice(string $status = 'sent'): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id])->id,
            'status' => $status,
            'total' => 100000,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function columns(string $table): array
    {
        return collect(Schema::getColumns($table))->keyBy('name')->all();
    }

    public function test_a_payment_may_have_no_receiver_and_may_use_the_midtrans_method(): void
    {
        $this->assertTrue($this->columns('payments')['received_by']['nullable']);

        $payment = Payment::create([
            'company_id' => $this->admin->company_id,
            'invoice_id' => $this->invoice()->id,
            'amount' => '1000.00',
            'method' => 'midtrans',
            'reference' => 'ORDER-X',
            'paid_at' => now(),
            'received_by' => null,
        ]);

        $this->assertSame('midtrans', $payment->fresh()->method);
        $this->assertNull($payment->fresh()->received_by);
    }

    public function test_the_method_column_still_refuses_unknown_methods(): void
    {
        $this->expectException(QueryException::class);

        Payment::create([
            'company_id' => $this->admin->company_id,
            'invoice_id' => $this->invoice()->id,
            'amount' => '1000.00',
            'method' => 'bitcoin',
            'paid_at' => now(),
            'received_by' => $this->admin->id,
        ]);
    }

    public function test_staff_stay_limited_to_the_manual_methods(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->admin)->postJson(route('admin.invoices.payments.store', $invoice), ['amount' => 1000, 'method' => 'midtrans'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_recording_a_payment_keeps_the_receiver_when_a_person_does_it_and_none_when_the_system_does(): void
    {
        $byPerson = $this->invoice();
        $bySystem = $this->invoice();

        $this->actingAs($this->admin);
        $manual = BillingService::recordPayment($byPerson, 1000, 'cash');
        $this->app['auth']->forgetGuards();
        CompanyService::useCompanyForTests(null);
        $gateway = CompanyService::runAs((int) $this->admin->company_id, fn () => BillingService::recordPayment($bySystem, 1000, 'midtrans', 'ORDER-Y'));

        $this->assertSame($this->admin->id, $manual->fresh()->received_by);
        $this->assertNull($gateway->fresh()->received_by);
    }

    public function test_a_person_can_still_reverse_a_gateway_payment(): void
    {
        $invoice = $this->invoice();
        $gateway = CompanyService::runAs((int) $this->admin->company_id, fn () => BillingService::recordPayment($invoice, 100000, 'midtrans', 'ORDER-Z'));
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($this->admin);
        BillingService::reversePayment($gateway, 'Customer was refunded by Midtrans');

        $this->assertNotNull($gateway->fresh()->cancelled_at);
        $this->assertSame($this->admin->id, $gateway->fresh()->cancelled_by);
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_the_attempts_table_enforces_unique_order_ids_unique_payments_and_known_statuses(): void
    {
        $attempt = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();
        $row = DB::table('payment_attempts')->where('id', $attempt->id)->first();
        $copy = (array) $row;
        unset($copy['id']);

        $this->assertThrowsQuery(fn () => DB::table('payment_attempts')->insert([...$copy, 'public_id' => PublicId::new()]), 'a duplicate order_id');
        $this->assertThrowsQuery(fn () => DB::table('payment_attempts')->insert([...$copy, 'public_id' => PublicId::new(), 'order_id' => 'other-order', 'status' => 'bogus']), 'an unknown status');

        $payment = Payment::create(['company_id' => $this->admin->company_id, 'invoice_id' => $attempt->invoice_id, 'amount' => '1.00', 'method' => 'midtrans', 'reference' => 'LINK', 'paid_at' => now()]);
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['payment_id' => $payment->id]);

        $this->assertThrowsQuery(fn () => DB::table('payment_attempts')->insert([...$copy, 'public_id' => PublicId::new(), 'order_id' => 'another-order', 'payment_id' => $payment->id]), 'two attempts closed by one payment');
    }

    private function assertThrowsQuery(callable $statement, string $what): void
    {
        try {
            $statement();
            $this->fail("The database accepted {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_attempts_table_is_indexed_for_the_invoice_panel_and_the_reconciliation_sweep(): void
    {
        $indexed = collect(Schema::getIndexes('payment_attempts'))->mapWithKeys(fn (array $index): array => [implode(',', $index['columns']) => $index['unique']]);

        $this->assertTrue($indexed['order_id']);
        $this->assertTrue($indexed['payment_id']);
        $this->assertArrayHasKey('company_id,invoice_id,status', $indexed->all());
        $this->assertArrayHasKey('status,created_at', $indexed->all());
    }

    public function test_rolling_back_refuses_while_gateway_payments_exist_and_changes_no_schema(): void
    {
        Payment::create([
            'company_id' => $this->admin->company_id,
            'invoice_id' => $this->invoice()->id,
            'amount' => '1000.00',
            'method' => 'midtrans',
            'reference' => 'ORDER-ROLLBACK',
            'paid_at' => now(),
            'received_by' => null,
        ]);

        foreach (['2026_10_12_600001_make_payments_received_by_nullable', '2026_10_12_600002_add_midtrans_to_payments_method_enum'] as $name) {
            $migration = require base_path("Modules/Billing/database/migrations/{$name}.php");

            try {
                $migration->down();
                $this->fail("{$name} must refuse to roll back while gateway payments exist.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($this->columns('payments')['received_by']['nullable']);
    }

    public function test_the_password_flag_defaults_to_off_and_the_backfill_only_flags_customer_accounts(): void
    {
        $this->assertFalse((bool) $this->admin->fresh()->getAttribute('must_change_password'));
        $company = Company::query()->findOrFail($this->admin->company_id);
        $customerLogin = User::factory()->create(['company_id' => $company->id]);
        $customerLogin->assignRole('customer');

        $migration = require base_path('database/migrations/2026_10_12_600004_flag_existing_customer_users_for_password_change.php');
        $migration->up();

        $this->assertTrue((bool) $customerLogin->fresh()->getAttribute('must_change_password'));
        $this->assertFalse((bool) $this->admin->fresh()->getAttribute('must_change_password'));

        $migration->down();

        $this->assertFalse((bool) $customerLogin->fresh()->getAttribute('must_change_password'));
    }
}
