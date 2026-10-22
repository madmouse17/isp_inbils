<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Services\Core\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CustomerDocumentsTabTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_customer_page_lists_document_types_and_expiring_documents(): void
    {
        Storage::fake('local');
        $admin = $this->createCompanyUser();
        $type = DocumentType::factory()->create([
            'company_id' => $admin->company_id,
            'name' => 'Kartu identitas',
            'code' => 'KTP',
            'applies_to' => 'Customer',
            'expiry_days' => 30,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin);
        DocumentService::upload($customer, UploadedFile::fake()->create('ktp.pdf', 120, 'application/pdf'), $type->code);

        $this->get(route('admin.customers.show', $customer))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Customers/Show')
            ->where('documentTypes.0.code', 'KTP')
            ->where('documents.0.file_name', 'ktp.pdf')
            ->where('documents.0.document_type_name', 'Kartu identitas')
            ->where('documents.0.expiry', 'expiring')
            ->where('can.update', true));
    }

    public function test_customer_page_hides_other_subject_and_inactive_document_types(): void
    {
        $admin = $this->createCompanyUser();
        DocumentType::factory()->create(['company_id' => $admin->company_id, 'code' => 'VEH', 'applies_to' => 'Vehicle', 'is_active' => true]);
        DocumentType::factory()->create(['company_id' => $admin->company_id, 'code' => 'OLD', 'applies_to' => 'Customer', 'is_active' => false]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin)->get(route('admin.customers.show', $customer))
            ->assertInertia(fn (Assert $page) => $page->has('documentTypes', 0)->has('documents', 0));
    }
}
