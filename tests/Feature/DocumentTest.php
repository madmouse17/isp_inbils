<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\User;
use App\Services\Core\DocumentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class DocumentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createCompanyUser();
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

        // Upload from other company context
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->actingAs($otherAdmin);
        \App\Services\Core\CompanyService::resetCache();
        $media = DocumentService::upload($otherCustomer, $file);

        // Admin from company A tries to delete media from company B
        $this->actingAs($this->admin);
        \App\Services\Core\CompanyService::resetCache();

        try {
            DocumentService::delete($media);
            // If no exception, check it wasn't deleted
            $this->assertDatabaseHas('media', ['id' => $media->id]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // 403 thrown — correct behavior
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.documents.index'));
        $response->assertOk();
    }
}
