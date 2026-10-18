<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\AddressLine;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;

/**
 * The migrations run once with the schema in place: the tests insert rows with empty snapshots (what existing data
 * looks like) and run `up()` again, which is what a half-finished or repeated migration does.
 */
class SnapshotBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_backfill_fills_non_draft_only_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id, 'name' => 'PT Maju', 'tax_id' => '01.234.567.8-901.000']);
        $installation = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Pasang', 'address' => 'Jl. Pasang 1', 'city' => 'Bandung', 'postal_code' => '40111']);
        $primary = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Utama', 'address' => 'Jl. Utama 2', 'city' => 'Cimahi', 'postal_code' => '40511', 'is_primary' => true]);
        $package = ServicePackageFactory::new()->create(['company_id' => $company->id, 'name' => 'Paket 20 Mbps']);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'service_package_id' => $package->id, 'installation_address_id' => $installation->id,
        ]);

        $make = fn (array $attributes) => Invoice::factory()->create($attributes + ['company_id' => $company->id, 'customer_id' => $customer->id]);
        $sent = $make(['status' => 'sent', 'subscription_id' => $subscription->id]);
        $noSubscription = $make(['status' => 'paid']);
        $draft = $make(['status' => 'draft', 'subscription_id' => $subscription->id]);
        $filled = $make(['status' => 'sent']);
        DB::table('invoices')->where('id', $filled->id)->update(['customer_name_snapshot' => 'Nama Lama', 'customer_address_snapshot' => 'Alamat Lama']);

        $migration = $this->migration('Billing', '2026_10_23_100200_add_snapshot_columns_to_invoices_table.php');
        $migration->up();
        $migration->up();

        $row = fn (Invoice $invoice) => DB::table('invoices')->where('id', $invoice->id)->first();

        $this->assertSame('PT Maju', $row($sent)->customer_name_snapshot);
        $this->assertSame('Jl. Pasang 1, Bandung, 40111', $row($sent)->customer_address_snapshot);
        $this->assertSame('01.234.567.8-901.000', $row($sent)->customer_tax_id_snapshot);
        $this->assertSame('Paket 20 Mbps', $row($sent)->package_name_snapshot);

        $this->assertSame('Jl. Utama 2, Cimahi, 40511', $row($noSubscription)->customer_address_snapshot);
        $this->assertNull($row($noSubscription)->package_name_snapshot);

        $this->assertNull($row($draft)->customer_name_snapshot);
        $this->assertSame(['Nama Lama', 'Alamat Lama', null], [
            $row($filled)->customer_name_snapshot, $row($filled)->customer_address_snapshot, $row($filled)->package_name_snapshot,
        ]);

        $customer->update(['name' => 'PT Berubah']);
        $migration->up();
        $this->assertSame('PT Maju', $row($sent)->customer_name_snapshot);
    }

    public function test_work_order_backfill_fills_completed_only(): void
    {
        $company = Company::factory()->create();
        $unit = OrganizationUnit::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id, 'name' => 'Budi Teknisi']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'organization_id' => $unit->id]);
        $location = Location::factory()->create(['company_id' => $company->id, 'code' => 'POP-01']);

        $make = fn (array $attributes) => WorkOrderFactory::new()->create($attributes + ['company_id' => $company->id, 'assigned_to' => $technician->id, 'location_id' => $location->id]);
        $completed = $make(['status' => 'completed']);
        $open = $make(['status' => 'in_progress']);
        $filled = $make(['status' => 'completed']);
        DB::table('work_orders')->where('id', $filled->id)->update(['technician_name_snapshot' => 'Nama Lama', 'location_code_snapshot' => 'LAMA']);

        $migration = $this->migration('SPK', '2026_10_23_100300_add_snapshot_columns_to_work_orders_table.php');
        $migration->up();
        $migration->up();

        $row = fn (WorkOrder $workOrder) => DB::table('work_orders')->where('id', $workOrder->id)->first();

        $this->assertSame(['Budi Teknisi', 'POP-01', $unit->id], [
            $row($completed)->technician_name_snapshot, $row($completed)->location_code_snapshot, $row($completed)->organization_unit_id,
        ]);
        $this->assertSame([null, null, null], [
            $row($open)->technician_name_snapshot, $row($open)->location_code_snapshot, $row($open)->organization_unit_id,
        ]);
        $this->assertSame(['Nama Lama', 'LAMA', null], [
            $row($filled)->technician_name_snapshot, $row($filled)->location_code_snapshot, $row($filled)->organization_unit_id,
        ]);
    }

    public function test_ticket_backfill_fills_closed_only(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create(['company_id' => $company->id]);
        $assignee = User::factory()->create(['company_id' => $company->id, 'name' => 'Sari Helpdesk']);
        $categoryId = DB::table('ticket_categories')->insertGetId([
            'public_id' => PublicId::new(), 'company_id' => $company->id, 'name' => 'Gangguan Fiber', 'code' => 'FIB',
            'default_sla_hours' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $make = function (string $code, string $status, array $extra = []) use ($company, $creator, $categoryId, $assignee): int {
            return DB::table('tickets')->insertGetId($extra + [
                'public_id' => PublicId::new(), 'company_id' => $company->id, 'code' => $code, 'title' => $code,
                'source' => 'internal', 'category_id' => $categoryId, 'status' => $status, 'assigned_to' => $assignee->id,
                'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $closed = $make('T-1', 'closed');
        $open = $make('T-2', 'open');
        $filled = $make('T-3', 'closed', ['category_name_snapshot' => 'Nama Lama']);

        $migration = $this->migration('Ticketing', '2026_10_23_100400_add_snapshot_columns_to_tickets_table.php');
        $migration->up();
        $migration->up();

        $row = fn (int $id) => DB::table('tickets')->where('id', $id)->first();

        $this->assertSame(['Gangguan Fiber', 'Sari Helpdesk'], [$row($closed)->category_name_snapshot, $row($closed)->assignee_name_snapshot]);
        $this->assertSame([null, null], [$row($open)->category_name_snapshot, $row($open)->assignee_name_snapshot]);
        $this->assertSame(['Nama Lama', null], [$row($filled)->category_name_snapshot, $row($filled)->assignee_name_snapshot]);
    }

    public function test_address_line_skips_blanks(): void
    {
        $this->assertNull(AddressLine::of(null));
        $this->assertNull(AddressLine::of(['address' => ' ', 'postal_code' => null]));
        $this->assertSame('Jl. A 1, Desa B, Kab. C, 12345', AddressLine::of([
            'address' => 'Jl. A 1', 'village_name' => 'Desa B', 'district_name' => '', 'city_name' => 'Kab. C', 'province_name' => null, 'postal_code' => '12345',
        ]));
        $this->assertSame('Jl. A 1, Kota X', AddressLine::of((object) ['address' => 'Jl. A 1', 'city_name' => null, 'city' => 'Kota X']));
    }

    public function test_units_are_active_by_default(): void
    {
        $company = Company::factory()->create();
        $id = DB::table('units')->insertGetId([
            'public_id' => PublicId::new(), 'company_id' => $company->id, 'name' => 'Meter', 'symbol' => 'm',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('units')->where('id', $id)->value('is_active'));
    }

    private function migration(string $module, string $file): object
    {
        return require base_path("Modules/{$module}/database/migrations/{$file}");
    }
}
