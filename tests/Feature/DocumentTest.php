<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class DocumentTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createCompanyUser();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_create_document_type(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'name' => 'KTP',
            'code' => 'KTP',
            'applies_to' => 'Customer',
            'is_required' => true,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('document_types', ['code' => 'KTP', 'name' => 'KTP']);
    }

    public function test_upload_media_to_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $file = UploadedFile::fake()->create('ktp.jpg', 500, 'image/jpeg');

        $this->actingAs($this->admin);
        $media = DocumentService::upload($customer, $file, null, 'documents');

        $this->assertDatabaseHas('media', [
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'file_name' => 'ktp.jpg',
        ]);
        $this->assertEquals($this->admin->company_id, $media->getCustomProperty('company_id'));
        $this->assertEquals($this->admin->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame('local', $media->disk);
        $this->assertSame('local', $media->conversions_disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_list_media_for_model(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $file1 = UploadedFile::fake()->create('a.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('b.pdf', 200, 'application/pdf');

        $this->actingAs($this->admin);
        DocumentService::upload($customer, $file1);
        DocumentService::upload($customer, $file2);

        $list = DocumentService::list($customer);
        $this->assertCount(2, $list);
    }

    public function test_delete_media_cross_company_blocked(): void
    {
        $otherCompany = Company::factory()->create();
        $otherCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);
        $file = UploadedFile::fake()->create('secret.pdf', 100, 'application/pdf');

        // Upload dari konteks perusahaan lain.
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherAdmin->assignRole('admin');
        $this->actingAs($otherAdmin);
        CompanyService::resetCache();
        $media = DocumentService::upload($otherCustomer, $file);

        // Admin perusahaan A mencoba menghapus media perusahaan B.
        $this->actingAs($this->admin);
        CompanyService::resetCache();

        try {
            DocumentService::delete($media);
            $this->assertDatabaseHas('media', ['id' => $media->id]);
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404]);
        }
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.documents.index'));
        $response->assertOk();
    }

    public function test_document_upload_rejects_foreign_model_type(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);
        $file = UploadedFile::fake()->create('ktp.jpg', 500, 'image/jpeg');

        $response = $this->actingAs($this->admin)->post(route('admin.documents.media.store'), [
            'model_type' => DocumentType::class,
            'model_id' => $foreignCustomer->id,
            'file' => $file,
            'collection' => 'documents',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('media', ['model_id' => $foreignCustomer->id, 'file_name' => 'ktp.jpg']);
    }

    public function test_document_upload_rejects_non_customer_model_type(): void
    {
        $file = UploadedFile::fake()->create('ktp.jpg', 500, 'image/jpeg');

        $response = $this->actingAs($this->admin)->post(route('admin.documents.media.store'), [
            'model_type' => Company::class,
            'model_id' => $this->admin->company_id,
            'file' => $file,
            'collection' => 'documents',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('media', ['model_type' => Company::class, 'model_id' => $this->admin->company_id]);
    }

    public function test_document_delete_rejects_foreign_tenant_media(): void
    {
        $otherCompany = Company::factory()->create();
        $otherCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherAdmin->assignRole('admin');
        $this->actingAs($otherAdmin);
        CompanyService::resetCache();
        $media = DocumentService::upload($otherCustomer, UploadedFile::fake()->create('owner.pdf', 120, 'application/pdf'));

        $this->actingAs($this->admin);
        CompanyService::resetCache();
        $response = $this->delete(route('admin.documents.media.destroy', $media));

        $response->assertNotFound();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_document_delete_removes_owned_media(): void
    {
        $this->actingAs($this->admin);
        CompanyService::resetCache();
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $media = DocumentService::upload($customer, UploadedFile::fake()->create('owned.pdf', 120, 'application/pdf'));

        $response = $this->delete(route('admin.documents.media.destroy', $media));

        $response->assertRedirect();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_document_upload_stores_owned_media(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $response = $this->actingAs($this->admin)->post(route('admin.documents.media.store'), [
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'file' => UploadedFile::fake()->create('valid.pdf', 120, 'application/pdf'),
            'document_type_code' => null,
            'collection' => 'documents',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('media', [
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'file_name' => 'valid.pdf',
        ]);
    }

    public function test_document_upload_rejects_without_permission(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $noPermUser = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($noPermUser)->post(route('admin.documents.media.store'), [
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'file' => UploadedFile::fake()->create('denied.pdf', 120, 'application/pdf'),
            'collection' => 'documents',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('media', ['file_name' => 'denied.pdf']);
    }

    public function test_document_upload_rejects_invalid_collection(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $file = UploadedFile::fake()->create('owner.pdf', 120, 'application/pdf');

        $response = $this->actingAs($this->admin)->post(route('admin.documents.media.store'), [
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'file' => $file,
            'collection' => 'invoices',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('media', ['model_type' => Customer::class, 'model_id' => $customer->id, 'file_name' => 'owner.pdf']);
    }

    public function test_document_upload_rejects_missing_customer_id(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.documents.media.store'), [
            'model_type' => Customer::class,
            'model_id' => 999999,
            'file' => UploadedFile::fake()->create('missing.pdf', 120, 'application/pdf'),
            'collection' => 'documents',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('media', ['file_name' => 'missing.pdf']);
    }

    public function test_document_type_delete_removes_record(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'name' => 'Delete Me',
            'code' => 'DEL-TEST',
            'applies_to' => 'Customer',
            'is_required' => false,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('document_types', ['code' => 'DEL-TEST']);

        $id = DocumentType::withoutCompany()->where('code', 'DEL-TEST')->first()->id;

        $response = $this->actingAs($this->admin)->delete(route('admin.documents.destroy', $id));
        $response->assertRedirect();
        $this->assertDatabaseMissing('document_types', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_document_type_delete_rejects_unauthorized_user(): void
    {
        $dt = DocumentType::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'name' => 'Foreign Doc Type',
            'code' => 'FD-001',
        ]);

        $otherCompany = Company::factory()->create();
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($otherAdmin)->delete(route('admin.documents.destroy', $dt->id));
        $response->assertNotFound();
        $this->assertDatabaseHas('document_types', ['id' => $dt->id, 'deleted_at' => null]);
    }

    public function test_document_type_delete_rejects_same_company_without_permission(): void
    {
        $dt = DocumentType::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'name' => 'Needs Setting',
            'code' => 'NS-001',
        ]);

        // Same company user, no admin role → no system.setting permission.
        $noPermUser = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($noPermUser)->delete(route('admin.documents.destroy', $dt->id));
        $response->assertForbidden();
        $this->assertDatabaseHas('document_types', ['id' => $dt->id, 'deleted_at' => null]);
    }

    public function test_create_keeps_the_active_switch_when_it_is_off(): void
    {
        $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'name' => 'Kontrak lama',
            'code' => 'KTR-OFF',
            'is_required' => false,
            'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(DocumentType::withoutCompany()->where('code', 'KTR-OFF')->firstOrFail()->is_active);
    }

    public function test_update_leaves_the_code_as_it_was(): void
    {
        $type = DocumentType::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'name' => 'KTP',
            'code' => 'KTP-OLD',
        ]);

        $this->actingAs($this->admin)->put(route('admin.documents.update', $type->id), [
            'name' => 'KTP baru',
            'code' => 'KTP-NEW',
            'is_required' => true,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertSame('KTP-OLD', $type->code);
        $this->assertSame('KTP baru', $type->name);
    }

    public function test_code_is_unique_within_the_company(): void
    {
        DocumentType::withoutCompany()->forceCreate(['company_id' => $this->admin->company_id, 'name' => 'KTP', 'code' => 'KTP']);

        $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'name' => 'KTP kedua',
            'code' => 'KTP',
        ])->assertSessionHasErrors(['code' => 'kode sudah digunakan.']);
    }

    public function test_index_follows_the_rows_per_page_the_table_asks_for(): void
    {
        foreach (range(1, 12) as $number) {
            DocumentType::withoutCompany()->forceCreate(['company_id' => $this->admin->company_id, 'name' => 'Jenis '.$number, 'code' => 'JNS-'.$number]);
        }

        $this->actingAs($this->admin)->get(route('admin.documents.index'))
            ->assertInertia(fn (Assert $page) => $page->has('documentTypes.data', 10));

        $this->actingAs($this->admin)->get(route('admin.documents.index', ['per_page' => 25]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documentTypes.data', 12)
                ->where('documentTypes.meta.per_page', 25)
                ->where('filters.per_page', '25'));
    }
}
