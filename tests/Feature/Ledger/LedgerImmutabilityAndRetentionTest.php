<?php

namespace Tests\Feature\Ledger;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Database\Factories\ProductFactory;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\StockService;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LedgerImmutabilityAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    /* --------------------------------------------------------------------------
     * 1. StockMovement Ledger Invariants
     * -------------------------------------------------------------------------- */

    public function test_stock_movement_cannot_be_updated(): void
    {
        $product = ProductFactory::new()->create(['company_id' => $this->company->id]);
        $movement = StockMovement::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'to_location_id' => $this->location->id,
            'movement_type' => 'receive',
            'quantity' => 10,
            'balance_after' => 10,
            'reserved_after' => 0,
            'created_by' => $this->user->id,
            'note' => 'Initial stock',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('StockMovement ledger entries are immutable and cannot be updated.');

        $movement->update(['quantity' => 20]);
    }

    public function test_stock_movement_cannot_be_deleted(): void
    {
        $product = ProductFactory::new()->create(['company_id' => $this->company->id]);
        $movement = StockMovement::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'to_location_id' => $this->location->id,
            'movement_type' => 'receive',
            'quantity' => 10,
            'balance_after' => 10,
            'reserved_after' => 0,
            'created_by' => $this->user->id,
            'note' => 'Initial stock',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('StockMovement ledger entries are immutable and cannot be deleted.');

        $movement->delete();
    }

    public function test_stock_movement_service_flow_appends_immutable_history(): void
    {
        $product = ProductFactory::new()->create(['company_id' => $this->company->id]);

        // Receive stock
        StockService::receive(
            productId: $product->id,
            locationId: $this->location->id,
            quantity: 15,
            note: 'Batch 1'
        );

        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->count());

        // Issue stock
        StockService::issue(
            productId: $product->id,
            locationId: $this->location->id,
            quantity: 5,
            note: 'Deploy'
        );

        $this->assertSame(2, StockMovement::query()->where('product_id', $product->id)->count());

        $movements = StockMovement::query()->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame('receive', $movements[0]->movement_type);
        $this->assertEquals(15.0, (float) $movements[0]->quantity);
        $this->assertEquals(15.0, (float) $movements[0]->balance_after);

        $this->assertSame('issue', $movements[1]->movement_type);
        $this->assertEquals(-5.0, (float) $movements[1]->quantity);
        $this->assertEquals(10.0, (float) $movements[1]->balance_after);
    }

    /* --------------------------------------------------------------------------
     * 2. Payment Ledger Invariants & Reversals
     * -------------------------------------------------------------------------- */

    public function test_payment_cannot_be_deleted(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-DEL-1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment records are immutable and cannot be deleted.');

        $payment->delete();
    }

    public function test_payment_financial_details_cannot_be_updated(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-UPD-1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment financial details are immutable and cannot be updated.');

        $payment->update(['amount' => 99999]);
    }

    public function test_cancelled_payment_cannot_be_modified(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $recorder = $this->user;
        $reverser = User::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-CAN-1');

        $this->actingAs($reverser);
        $cancelled = BillingService::reversePayment($payment, 'Customer requested refund');

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Customer requested refund', $cancelled->cancel_reason);
        $this->assertSame($reverser->id, $cancelled->cancelled_by);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cancelled payment cannot be modified.');

        $cancelled->update(['cancel_reason' => 'Changed my mind']);
    }

    public function test_direct_payment_cancellation_via_eloquent_is_forbidden_and_preserves_state(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-NO-BYPASS');
        $invoice = $invoice->fresh();
        $this->assertEquals(50000.0, (float) $invoice->paid_amount);
        $this->assertSame('partial', $invoice->status);

        $initialActivityCount = Activity::query()
            ->where('log_name', 'invoice')
            ->where('description', 'payment_reversed')
            ->count();

        try {
            $payment->update([
                'cancelled_at' => now(),
                'cancel_reason' => 'Direct bypass attempt',
                'cancelled_by' => $this->user->id,
            ]);
            $this->fail('Direct payment cancellation via Eloquent should have been blocked.');
        } catch (LogicException $e) {
            $this->assertSame(
                'Direct payment cancellation is forbidden. Payments must be reversed via BillingService::reversePayment().',
                $e->getMessage()
            );
        }

        // Verify state is strictly untouched fail-closed
        $freshPayment = $payment->fresh();
        $this->assertNull($freshPayment->cancelled_at);
        $this->assertNull($freshPayment->cancel_reason);
        $this->assertNull($freshPayment->cancelled_by);

        $freshInvoice = $invoice->fresh();
        $this->assertEquals(50000.0, (float) $freshInvoice->paid_amount);
        $this->assertSame('partial', $freshInvoice->status);

        $this->assertSame(
            $initialActivityCount,
            Activity::query()
                ->where('log_name', 'invoice')
                ->where('description', 'payment_reversed')
                ->count()
        );
    }

    public function test_direct_mutation_of_individual_reversal_fields_is_forbidden(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-FIELD-GUARD');

        // Test cancelled_at alone
        try {
            $payment->update(['cancelled_at' => now()]);
            $this->fail('Direct update of cancelled_at must be blocked.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Direct payment cancellation is forbidden', $e->getMessage());
        }

        // Test cancel_reason alone
        try {
            $payment->update(['cancel_reason' => 'Tampered reason']);
            $this->fail('Direct update of cancel_reason must be blocked.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Direct payment cancellation is forbidden', $e->getMessage());
        }

        // Test cancelled_by alone
        try {
            $payment->update(['cancelled_by' => $this->user->id]);
            $this->fail('Direct update of cancelled_by must be blocked.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Direct payment cancellation is forbidden', $e->getMessage());
        }
    }

    public function test_payment_cannot_be_created_in_cancelled_state(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('New payments cannot be created in a cancelled state.');

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 50000,
            'method' => 'transfer',
            'reference' => 'REF-PRE-CANCELLED',
            'paid_at' => now(),
            'received_by' => $this->user->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'Pre-cancelled bypass attempt',
            'cancelled_by' => $this->user->id,
        ]);
    }

    public function test_payment_reversal_authorization_cannot_be_granted_outside_billing_service(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment reversal authorization can only be granted by BillingService::reversePayment().');

        Payment::executeInCanonicalReversal(function () {
            // Should not be reachable
        });
    }

    /* --------------------------------------------------------------------------
     * 3. NetworkAsset & Installation History Retention
     * -------------------------------------------------------------------------- */

    public function test_installation_history_cannot_be_deleted(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);

        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NetworkAssetInstallation history cannot be deleted.');

        $installation->delete();
    }

    public function test_closed_installation_cannot_be_updated(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);
        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->firstOrFail();

        NetworkAssetService::remove($asset, 'Contract ended');
        $closed = $installation->fresh();

        $this->assertNotNull($closed->removed_at);
        $this->assertSame('Contract ended', $closed->removal_reason);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Closed installation record is immutable and cannot be updated.');

        $closed->update(['removal_reason' => 'Tampered reason']);
    }

    public function test_asset_force_delete_is_blocked_when_installation_history_exists(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);
        NetworkAssetService::remove($asset, 'Relocated');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot hard delete network asset with installation history.');

        $asset->forceDelete();
    }

    public function test_soft_deleting_asset_preserves_installation_history_and_relationship(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
            'code' => 'NA-CODE-001',
            'serial_number' => 'SN-TEST-001',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);
        NetworkAssetService::remove($asset, 'Customer moved out');

        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->firstOrFail();
        $this->assertNotNull($installation->removed_at);

        // Soft delete the asset
        $asset->delete();
        $this->assertSoftDeleted('network_assets', ['id' => $asset->id]);

        // Installation record is still in database and not cascaded
        $this->assertDatabaseHas('network_asset_installations', ['id' => $installation->id]);

        // BelongsTo relationship resolves with withTrashed()
        $freshInstallation = $installation->fresh();
        $this->assertNotNull($freshInstallation->asset);
        $this->assertSame($asset->id, $freshInstallation->asset->id);
        $this->assertSame('NA-CODE-001', $freshInstallation->asset->code);
    }

    public function test_installation_captures_asset_snapshot_at_install_time(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
            'code' => 'NA-SNAP-123',
            'serial_number' => 'SN-SNAP-456',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);

        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->firstOrFail();
        $this->assertSame('NA-SNAP-123', $installation->asset_code_snapshot);
        $this->assertSame('SN-SNAP-456', $installation->asset_serial_snapshot);
    }

    /* --------------------------------------------------------------------------
     * 4. Rollback Schema Contract & Historical Retention Invariants
     * -------------------------------------------------------------------------- */

    public function test_actor_columns_migration_down_is_non_destructive_and_preserves_columns_and_data(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $recorder = $this->user;
        $reverser = User::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $payment = BillingService::recordPayment($invoice, 50000, 'transfer', 'REF-ROLLBACK-1');

        $this->actingAs($reverser);
        $cancelled = BillingService::reversePayment($payment, 'Rollback safety test');
        $this->assertSame($reverser->id, $cancelled->cancelled_by);

        // Execute migration down
        /** @var Migration $migration */
        $migration = require base_path('Modules/Billing/database/migrations/2026_08_14_130003_add_actor_columns_to_payments_and_invoices.php');
        $migration->down();

        // Verify columns are NOT dropped
        $this->assertTrue(Schema::hasColumn('payments', 'cancelled_by'));
        $this->assertTrue(Schema::hasColumn('invoices', 'cancelled_by'));

        // Verify existing historical actor data is retained
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->cancelled_by);
        $this->assertSame($reverser->id, $fresh->cancelled_by);
    }

    public function test_network_asset_installation_retention_migration_down_is_non_destructive(): void
    {
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'available',
            'code' => 'NA-ROLLBACK-001',
            'serial_number' => 'SN-ROLLBACK-001',
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        NetworkAssetService::install($asset, $this->location->id, $customer->id);
        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->firstOrFail();
        $this->assertSame('NA-ROLLBACK-001', $installation->asset_code_snapshot);
        $this->assertSame('SN-ROLLBACK-001', $installation->asset_serial_snapshot);

        // Execute migration down
        /** @var Migration $migration */
        $migration = require base_path('Modules/NetworkAsset/database/migrations/2026_09_07_110002_harden_network_asset_installation_retention.php');
        $migration->down();

        // Verify snapshot columns are NOT dropped
        $this->assertTrue(Schema::hasColumn('network_asset_installations', 'asset_code_snapshot'));
        $this->assertTrue(Schema::hasColumn('network_asset_installations', 'asset_serial_snapshot'));

        // Verify existing snapshot data is retained
        $freshInstallation = $installation->fresh();
        $this->assertSame('NA-ROLLBACK-001', $freshInstallation->asset_code_snapshot);
        $this->assertSame('SN-ROLLBACK-001', $freshInstallation->asset_serial_snapshot);

        // Verify asset deletion is still prevented and cannot cascade delete installation history
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot hard delete network asset with installation history.');
        $asset->forceDelete();
    }
}
