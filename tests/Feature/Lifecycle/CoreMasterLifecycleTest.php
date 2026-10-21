<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\DocumentType;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Services\Core\DocumentService;
use App\Services\Core\LocationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Service\Models\ServicePackage;
use PDOException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The core masters (spec .scratch/master-lifecycle): a location, customer, address or document type that anything
 * points at is deactivated, never deleted, and a hard delete happens only when nothing ever pointed at it. The status
 * filter, Pulihkan and the reference counts that the pages read follow the same rule.
 */
class CoreMasterLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->user('admin');
    }

    public function test_a_location_with_children_is_refused_and_can_only_be_deactivated(): void
    {
        $region = $this->location('Jawa');
        $this->location('Barat', 'area', $region);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.locations.destroy', $region))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi masih dipakai 1 lokasi turunan. Nonaktifkan saja.']);
        $this->assertDatabaseHas('locations', ['id' => $region->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.locations.deactivate', $region->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Lokasi dinonaktifkan.');
        $this->assertFalse($region->fresh()->is_active);

        $this->from('/back')->post(route('admin.locations.activate', $region->public_id))
            ->assertSessionHas('success', 'Lokasi diaktifkan.');
        $this->assertTrue($region->fresh()->is_active);
    }

    public function test_the_index_counts_what_points_at_a_location_and_the_refusal_names_it(): void
    {
        $region = $this->location('Jawa');
        $area = $this->location('Barat', 'area', $region);
        $pop = $this->location('POP Satu', 'pop', $area);
        $this->location('Rak Satu', 'rack', $pop);
        $this->subscription($this->customer('Budi'), ['serving_pop_id' => $pop->id]);
        $this->location('Kosong', 'pop', $area);

        $rows = collect($this->props($this->actingAs($this->admin)->get(route('admin.locations.index', ['per_page' => 50])))['locations']['data'])->keyBy('name');

        $this->assertSame(['lokasi turunan' => 1, 'langganan' => 1], $this->counted($rows['POP Satu']));
        $this->assertSame(['lokasi turunan' => 2], $this->counted($rows['Barat']));
        $this->assertSame(['lokasi turunan' => 1], $this->counted($rows['Jawa']));
        $this->assertSame([], $this->counted($rows['Kosong']));
        $this->assertCount(16, $rows['Kosong']['references'], 'every label is listed, the unused ones at zero');
        $this->assertNull($rows['Kosong']['deleted_at']);

        $this->deleteJson(route('admin.locations.destroy', $pop))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi masih dipakai 1 lokasi turunan, 1 langganan. Nonaktifkan saja.']);
    }

    public function test_a_location_with_a_stock_row_is_refused_even_when_the_stock_is_empty(): void
    {
        $warehouse = $this->location('Gudang');
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        Stock::query()->create(['company_id' => $this->company->id, 'product_id' => $product->id, 'location_id' => $warehouse->id, 'quantity' => 0, 'reserved_quantity' => 0]);

        $this->actingAs($this->admin)->deleteJson(route('admin.locations.destroy', $warehouse))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi masih dipakai 1 stok. Nonaktifkan saja.']);
        $this->assertDatabaseHas('locations', ['id' => $warehouse->id, 'deleted_at' => null]);
    }

    public function test_an_unreferenced_location_is_hard_deleted_and_audited(): void
    {
        $location = $this->location('Sementara');

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.locations.destroy', $location))
            ->assertRedirect('/back')->assertSessionHas('success', 'Lokasi dihapus permanen.');

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertTrue(Activity::query()->where('description', 'force_deleted')->where('properties->id', $location->public_id)->exists());
    }

    public function test_location_restore_is_refused_while_its_parent_is_still_deleted(): void
    {
        $region = $this->location('Jawa');
        $area = $this->location('Barat', 'area', $region);
        $area->delete();
        $region->delete();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.locations.restore', $area->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi tidak bisa dipulihkan karena induknya, Jawa, masih dihapus. Pulihkan induknya dulu.']);
        $this->assertSoftDeleted($area);

        $this->from('/back')->post(route('admin.locations.restore', $region->public_id))->assertSessionHas('success', 'Lokasi dipulihkan.');
        $this->post(route('admin.locations.restore', $area->public_id))->assertSessionHas('success', 'Lokasi dipulihkan.');
        $this->assertNotSoftDeleted($area);
        $this->assertNotSoftDeleted($region);
    }

    public function test_a_restored_location_takes_the_current_path_of_its_parent(): void
    {
        $region = $this->location('Jawa', 'region', null, ['code' => 'JAWA']);
        $area = $this->location('Barat', 'area', $region, ['code' => 'BARAT']);
        $area->delete();
        $this->actingAs($this->admin);

        LocationService::update($region, ['code' => 'JAWA-1']);
        $this->assertSame('JAWA > BARAT', Location::onlyTrashed()->findOrFail($area->id)->path, 'a trashed row is skipped by the parent rename');

        $this->from('/back')->post(route('admin.locations.restore', $area->public_id))->assertSessionHas('success', 'Lokasi dipulihkan.');

        $this->assertSame('JAWA-1 > BARAT', $area->fresh()->path);
    }

    public function test_a_reused_code_is_refused_on_restore(): void
    {
        $location = $this->location('Lama');
        $location->delete();
        // A deleted row keeps its code reserved in the unique key, so no second row can take it; this stands in for a
        // database without that key, where the refusal is the only guard.
        Location::restoring(function (): void {
            throw new UniqueConstraintViolationException('mysql', 'update `locations` set `deleted_at` = ? where `id` = ?', [], new PDOException('Duplicate entry'));
        });

        $this->actingAs($this->admin)->postJson(route('admin.locations.restore', $location->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi tidak bisa dipulihkan karena kode atau namanya sudah dipakai data lain. Ubah data itu dulu.']);
        $this->assertSoftDeleted($location);
    }

    public function test_the_code_of_a_deleted_row_stays_reserved_for_locations_and_document_types(): void
    {
        $location = $this->location('Lama', attributes: ['code' => 'JKT-1']);
        $location->delete();
        $type = $this->documentType('KTP');
        $type->delete();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.locations.store'), ['code' => 'JKT-1', 'name' => 'Baru', 'type' => 'region'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson(route('admin.documents.store'), ['code' => 'KTP', 'name' => 'Baru'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_location_index_status_filter_values(): void
    {
        $this->location('Aktif', attributes: ['code' => 'A-AKTIF', 'lat' => -6.2, 'lng' => 106.8]);
        $this->location('Nonaktif', attributes: ['code' => 'B-NONAKTIF', 'is_active' => false]);
        $this->location('Terhapus', attributes: ['code' => 'C-DIHAPUS', 'lat' => -6.3, 'lng' => 106.9])->delete();

        $this->assertSame(['A-AKTIF', 'B-NONAKTIF'], $this->codes('admin.locations.index', 'locations'));
        $this->assertSame(['A-AKTIF', 'B-NONAKTIF'], $this->codes('admin.locations.index', 'locations', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['A-AKTIF'], $this->codes('admin.locations.index', 'locations', 'aktif'));
        $this->assertSame(['B-NONAKTIF'], $this->codes('admin.locations.index', 'locations', 'nonaktif'));
        $this->assertSame(['C-DIHAPUS'], $this->codes('admin.locations.index', 'locations', 'dihapus'));
        $this->assertSame(['A-AKTIF', 'B-NONAKTIF', 'C-DIHAPUS'], $this->codes('admin.locations.index', 'locations', 'semua'));

        $response = $this->actingAs($this->admin)->get(route('admin.locations.index', ['status' => 'dihapus']))->assertOk();
        $props = $this->props($response);
        $this->assertSame('dihapus', $props['filters']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $props['locations']['data'][0]['deleted_at']);
        $this->assertSame(['C-DIHAPUS'], collect($props['markers'])->pluck('code')->all(), 'the map follows the same filter');
        $this->assertNull($this->props($this->actingAs($this->admin)->get(route('admin.locations.index', ['status' => 'bogus'])))['filters']['status']);
    }

    public function test_every_location_reference_count_reads_a_foreign_key_index(): void
    {
        $this->actingAs($this->admin);

        $subSelects = Location::query()->withReferenceCounts()->orderBy('path')->limit(25)->explain()->where('select_type', 'DEPENDENT SUBQUERY');

        $this->assertCount(18, $subSelects, 'one sub-select per declared reference column');
        foreach ($subSelects as $step) {
            $this->assertSame('ref', $step->type, $step->table);
            $this->assertNotNull($step->key, $step->table);
        }
    }

    public function test_a_customer_with_subscriptions_is_deactivated_never_hard_deleted(): void
    {
        $customer = $this->customer('Budi');
        $terminated = $this->subscription($customer, ['status' => 'terminated', 'terminated_at' => now()]);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.customers.destroy', $customer))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Pelanggan masih dipakai 1 langganan. Nonaktifkan saja.']);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);

        $terminated->delete();
        $this->deleteJson(route('admin.customers.destroy', $customer))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Pelanggan masih dipakai 1 langganan. Nonaktifkan saja.']);

        $this->from('/back')->post(route('admin.customers.deactivate', $customer->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Pelanggan dinonaktifkan.');
        $this->assertFalse($customer->fresh()->is_active);

        $this->from('/back')->post(route('admin.customers.activate', $customer->public_id))
            ->assertSessionHas('success', 'Pelanggan diaktifkan.');
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_an_unreferenced_customer_is_hard_deleted_with_its_addresses_and_contacts(): void
    {
        $customer = $this->customer('Sementara');
        $this->address($customer, 'Rumah');
        $this->address($customer, 'Kantor');
        CustomerContact::factory()->create(['customer_id' => $customer->id, 'company_id' => $this->company->id]);

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.customers.destroy', $customer))
            ->assertRedirect('/back')->assertSessionHas('success', 'Pelanggan dihapus permanen.');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('customer_contacts', ['customer_id' => $customer->id]);
    }

    public function test_the_customer_index_counts_references_lists_deleted_rows_and_restore_brings_one_back(): void
    {
        $used = $this->customer('Terpakai', ['code' => 'C-USED']);
        $this->subscription($used);
        $this->customer('Bebas', ['code' => 'C-FREE']);
        $gone = $this->customer('Terhapus', ['code' => 'C-GONE']);
        $gone->delete();

        $rows = collect($this->props($this->actingAs($this->admin)->get(route('admin.customers.index')))['customers']['data'])->keyBy('code');

        $this->assertSame(['C-FREE', 'C-USED'], $rows->keys()->sort()->values()->all());
        $this->assertSame(['langganan' => 1], $this->counted($rows['C-USED']));
        $this->assertSame([], $this->counted($rows['C-FREE']));
        $this->assertSame(['C-GONE'], $this->codes('admin.customers.index', 'customers', 'dihapus'));
        $this->assertSame(['C-FREE', 'C-GONE', 'C-USED'], $this->codes('admin.customers.index', 'customers', 'semua'));
        $this->assertSame(['C-FREE', 'C-USED'], $this->codes('admin.customers.index', 'customers', 'aktif'));
        $this->assertSame([], $this->codes('admin.customers.index', 'customers', 'nonaktif'));

        $this->from('/back')->post(route('admin.customers.restore', $gone->public_id))->assertSessionHas('success', 'Pelanggan dipulihkan.');
        $this->assertNotSoftDeleted($gone);
        $this->assertSame([], $this->codes('admin.customers.index', 'customers', 'dihapus'));
    }

    public function test_the_customer_status_values_still_filter_by_the_derived_status(): void
    {
        $this->customer('Aktif', ['code' => 'C-ACT', 'customer_status' => 'active']);
        $this->customer('Tunda', ['code' => 'C-SUS', 'customer_status' => 'suspended']);

        $this->assertSame(['C-SUS'], $this->codes('admin.customers.index', 'customers', 'suspended'));
    }

    public function test_an_address_used_by_a_subscription_is_refused_and_cannot_be_deactivated(): void
    {
        $customer = $this->customer('Budi');
        $address = $this->address($customer, 'Pasang', ['is_primary' => true, 'is_installation_point' => true]);
        $this->subscription($customer, ['installation_address_id' => $address->id]);

        $this->actingAs($this->admin)->deleteJson(route('admin.customers.addresses.destroy', [$customer, $address]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Alamat masih dipakai 1 langganan, jadi tidak bisa dihapus.']);

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id, 'deleted_at' => null]);
        $this->assertTrue((bool) $address->fresh()->is_primary);
        $this->assertFalse(Route::has('admin.customers.addresses.deactivate'), 'an address has no active flag');
    }

    public function test_address_hard_delete_promotes_the_markers_to_the_oldest_remaining_address(): void
    {
        $customer = $this->customer('Budi');
        $primary = $this->address($customer, 'Utama', ['is_primary' => true, 'is_installation_point' => true]);
        $second = $this->address($customer, 'Kedua');
        $third = $this->address($customer, 'Ketiga');

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.customers.addresses.destroy', [$customer, $primary]))
            ->assertRedirect('/back')->assertSessionHas('success', 'Alamat dihapus permanen.');

        $this->assertDatabaseMissing('customer_addresses', ['id' => $primary->id]);
        $this->assertSame([true, true], [(bool) $second->fresh()->is_primary, (bool) $second->fresh()->is_installation_point]);
        $this->assertSame([false, false], [(bool) $third->fresh()->is_primary, (bool) $third->fresh()->is_installation_point]);
    }

    public function test_address_restore_clears_a_marker_that_another_address_now_holds(): void
    {
        $customer = $this->customer('Budi');
        $old = $this->address($customer, 'Lama', ['is_primary' => true, 'is_installation_point' => true]);
        $current = $this->address($customer, 'Sekarang');
        // The delete of an earlier version of the app: the row was only hidden, and the markers moved on.
        $old->delete();
        $current->update(['is_primary' => true, 'is_installation_point' => true]);

        $this->actingAs($this->admin)->from('/back')->post(route('admin.customers.addresses.restore', $old->public_id))
            ->assertSessionHas('success', 'Alamat dipulihkan.');

        $this->assertSame([false, false], [(bool) $old->fresh()->is_primary, (bool) $old->fresh()->is_installation_point], 'the customer keeps exactly one of each');
        $this->assertSame([true, true], [(bool) $current->fresh()->is_primary, (bool) $current->fresh()->is_installation_point]);
    }

    public function test_address_restore_into_an_empty_set_keeps_or_takes_the_markers(): void
    {
        $customer = $this->customer('Sendiri');
        $marked = $this->address($customer, 'Bertanda', ['is_primary' => true, 'is_installation_point' => true]);
        $marked->delete();
        $this->actingAs($this->admin)->post(route('admin.customers.addresses.restore', $marked->public_id));
        $this->assertSame([true, true], [(bool) $marked->fresh()->is_primary, (bool) $marked->fresh()->is_installation_point], 'with no other address the markers stay');

        $other = $this->customer('Lain');
        $plain = $this->address($other, 'Biasa');
        $plain->delete();
        $this->post(route('admin.customers.addresses.restore', $plain->public_id));
        $this->assertSame([true, true], [(bool) $plain->fresh()->is_primary, (bool) $plain->fresh()->is_installation_point], 'a customer is never left with an address and no marker');
    }

    public function test_deleted_addresses_list_under_dihapus_with_their_references(): void
    {
        $customer = $this->customer('Budi');
        $this->address($customer, 'Hidup');
        $this->address($customer, 'Terhapus')->delete();
        $this->actingAs($this->admin);

        $props = $this->props($this->get(route('admin.customers.addresses.index', [$customer, 'status' => 'dihapus'])));

        $this->assertSame('dihapus', $props['filters']['status']);
        $this->assertSame(['Terhapus'], collect($props['addresses']['data'])->pluck('label')->all());
        $this->assertNotNull($props['addresses']['data'][0]['deleted_at']);
        $this->assertSame(['Hidup'], collect($this->props($this->get(route('admin.customers.addresses.index', $customer)))['addresses']['data'])->pluck('label')->all());
        $this->assertSame(['langganan' => 0], collect($this->props($this->get(route('admin.customers.addresses.index', $customer)))['addresses']['data'][0]['references'])->pluck('count', 'label')->all());
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());
    }

    public function test_a_document_type_with_a_document_is_refused_and_an_unused_one_is_deleted(): void
    {
        Storage::fake('local');
        $used = $this->documentType('KTP');
        $free = $this->documentType('SIM');
        $customer = $this->customer('Pemilik');
        $this->actingAs($this->admin);
        DocumentService::upload($customer, UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf'), 'KTP');

        $this->deleteJson(route('admin.documents.destroy', $used->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Jenis dokumen masih dipakai 1 dokumen. Nonaktifkan saja.']);
        $this->assertDatabaseHas('document_types', ['id' => $used->id, 'deleted_at' => null]);

        $rows = collect($this->props($this->get(route('admin.documents.index')))['documentTypes']['data'])->keyBy('code');
        $this->assertSame(['dokumen' => 1], $this->counted($rows['KTP']));
        $this->assertSame([], $this->counted($rows['SIM']));

        $this->from('/back')->delete(route('admin.documents.destroy', $free->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Jenis dokumen dihapus permanen.');
        $this->assertDatabaseMissing('document_types', ['id' => $free->id]);
    }

    public function test_a_document_type_deactivates_restores_and_filters(): void
    {
        $this->documentType('AKT', ['is_active' => true]);
        $off = $this->documentType('NON', ['is_active' => false]);
        $gone = $this->documentType('DEL');
        $gone->delete();
        $this->actingAs($this->admin);

        $this->assertSame(['AKT', 'NON'], $this->codes('admin.documents.index', 'documentTypes'));
        $this->assertSame(['AKT'], $this->codes('admin.documents.index', 'documentTypes', 'aktif'));
        $this->assertSame(['NON'], $this->codes('admin.documents.index', 'documentTypes', 'nonaktif'));
        $this->assertSame(['DEL'], $this->codes('admin.documents.index', 'documentTypes', 'dihapus'));
        $this->assertSame(['AKT', 'DEL', 'NON'], $this->codes('admin.documents.index', 'documentTypes', 'semua'));

        $props = $this->props($this->get(route('admin.documents.index', ['status' => 'dihapus'])));
        $this->assertSame('dihapus', $props['filters']['status']);
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());

        $this->from('/back')->post(route('admin.documents.activate', $off->public_id))->assertSessionHas('success', 'Jenis dokumen diaktifkan.');
        $this->assertTrue($off->fresh()->is_active);
        $this->post(route('admin.documents.deactivate', $off->public_id))->assertSessionHas('success', 'Jenis dokumen dinonaktifkan.');
        $this->post(route('admin.documents.restore', $gone->public_id))->assertSessionHas('success', 'Jenis dokumen dipulihkan.');
        $this->assertNotSoftDeleted($gone);
    }

    public function test_deactivate_needs_the_update_permission_and_restore_the_delete_permission(): void
    {
        $updater = $this->user('core-updater', ['location.view', 'location.update', 'customer.view', 'customer.update']);
        $deleter = $this->user('core-deleter', ['location.view', 'location.delete', 'customer.view', 'customer.delete']);
        $viewer = $this->user('core-viewer', ['location.view', 'customer.view']);

        foreach (['locations' => $this->location('Lokasi'), 'customers' => $this->customer('Pelanggan')] as $base => $record) {
            $deactivate = route("admin.{$base}.deactivate", $record->public_id);
            $restore = route("admin.{$base}.restore", $record->public_id);

            $this->actingAs($viewer)->postJson($deactivate)->assertForbidden();
            $this->actingAs($deleter)->postJson($deactivate)->assertForbidden();
            $this->assertTrue($record->fresh()->is_active);

            $this->actingAs($updater)->postJson($deactivate)->assertRedirect();
            $this->assertFalse($record->fresh()->is_active);

            $record->delete();
            $this->actingAs($viewer)->postJson($restore)->assertForbidden();
            $this->actingAs($updater)->postJson($restore)->assertForbidden();
            $this->assertSoftDeleted($record);

            $this->actingAs($deleter)->postJson($restore)->assertRedirect();
            $this->assertNotSoftDeleted($record);
        }
    }

    public function test_address_and_document_type_restore_follow_their_own_permissions(): void
    {
        $customer = $this->customer('Budi');
        $address = $this->address($customer, 'Lama');
        $address->delete();
        $type = $this->documentType('KTP');
        $type->delete();
        $viewer = $this->user('core-viewer', ['customer.view']);
        $manager = $this->user('core-address-manager', ['customer.view', 'customer.address.manage']);

        $this->actingAs($viewer)->postJson(route('admin.customers.addresses.restore', $address->public_id))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('admin.documents.restore', $type->public_id))->assertForbidden();
        $this->actingAs($manager)->postJson(route('admin.documents.restore', $type->public_id))->assertForbidden();
        $this->assertSoftDeleted($address);
        $this->assertSoftDeleted($type);

        $this->actingAs($manager)->postJson(route('admin.customers.addresses.restore', $address->public_id))->assertRedirect();
        $this->assertNotSoftDeleted($address);
    }

    public function test_another_companys_records_answer_404_on_every_action(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['company_id' => $other->id]);
        $records = [
            'locations' => Location::factory()->create(['company_id' => $other->id]),
            'customers' => $customer,
            'documents' => DocumentType::factory()->create(['company_id' => $other->id]),
        ];
        $address = CustomerAddress::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($this->admin);

        foreach ($records as $base => $record) {
            $this->postJson(route("admin.{$base}.deactivate", $record->public_id))->assertNotFound();
            $this->postJson(route("admin.{$base}.activate", $record->public_id))->assertNotFound();
            $this->deleteJson(route("admin.{$base}.destroy", $record->public_id))->assertNotFound();
        }
        $this->postJson(route('admin.customers.addresses.restore', $address->public_id))->assertNotFound();

        foreach ([...$records, 'customers.addresses' => $address] as $base => $record) {
            $record->delete();
            $this->postJson(route("admin.{$base}.restore", $record->public_id))->assertNotFound();
            $this->assertSoftDeleted($record);
        }
    }

    public function test_every_core_lifecycle_route_is_registered_with_its_model(): void
    {
        $all = ['deactivate', 'activate', 'restore'];
        $registered = [
            'admin.locations' => ['locations', Location::class, $all],
            'admin.customers' => ['customers', Customer::class, $all],
            'admin.customers.addresses' => ['customer-addresses', CustomerAddress::class, ['restore']],
            'admin.documents' => ['documents', DocumentType::class, $all],
            'admin.vehicles' => ['vehicles', Vehicle::class, $all],
            'admin.organizations' => ['organizations', OrganizationUnit::class, $all],
            'admin.employees' => ['employees', EmployeeProfile::class, ['restore']],
            'admin.evaluations' => ['evaluations', EmployeeEvaluation::class, ['restore']],
        ];

        foreach ($registered as $name => [$uri, $model, $actions]) {
            foreach ($all as $action) {
                $route = Route::getRoutes()->getByName("{$name}.{$action}");

                if (! in_array($action, $actions, true)) {
                    $this->assertNull($route, "{$name}.{$action} is not offered");

                    continue;
                }

                $this->assertNotNull($route, "{$name}.{$action}");
                $this->assertSame("admin/{$uri}/{record}/{$action}", $route->uri());
                $this->assertSame(['POST'], $route->methods());
                $this->assertSame($model, $route->defaults['lifecycleModel']);
                $this->assertContains('auth', $route->gatherMiddleware());
            }
        }

        $change = Route::getRoutes()->getByName('admin.subscriptions.change-package');

        $this->assertNotNull($change);
        $this->assertSame('admin/subscriptions/{subscription}/change-package', $change->uri());
        $this->assertSame(['POST'], $change->methods());
    }

    /** @param  list<string>  $permissions  none: the template or role of that name is used as it is; `app.web` opens the admin pages for a role of exactly these */
    private function user(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantRole($user, $permissions === [] ? $role : $this->companyRole($this->company, $role, [...$permissions, 'app.web']));
    }

    /** @param  array<string, mixed>  $attributes */
    private function location(string $name, string $type = 'region', ?Location $parent = null, array $attributes = []): Location
    {
        $code = $attributes['code'] ?? strtoupper($type).'-'.++$this->sequence;

        return Location::factory()->create([
            'company_id' => $this->company->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'path' => ($parent === null ? '' : $parent->path.' > ').$code,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function customer(string $name, array $attributes = []): Customer
    {
        return Customer::factory()->create(['company_id' => $this->company->id, 'name' => $name, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function address(Customer $customer, string $label, array $attributes = []): CustomerAddress
    {
        return CustomerAddress::factory()->create(['customer_id' => $customer->id, 'label' => $label, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function documentType(string $code, array $attributes = []): DocumentType
    {
        return DocumentType::factory()->create(['company_id' => $this->company->id, 'code' => $code, 'name' => 'Jenis '.$code, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function subscription(Customer $customer, array $attributes = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $this->company->id])->id,
            'installation_address_id' => $this->address($customer, 'Pasang '.++$this->sequence)->id,
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int> the reference counts of a row above zero, by label
     */
    private function counted(array $row): array
    {
        return collect($row['references'])->where('count', '>', 0)->pluck('count', 'label')->all();
    }

    /** @return list<string> the codes an index lists for a status, sorted */
    private function codes(string $route, string $prop, ?string $status = null): array
    {
        $response = $this->actingAs($this->admin)->get(route($route, $status === null ? [] : ['status' => $status]))->assertOk();

        return collect($this->props($response)[$prop]['data'])->pluck('code')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
