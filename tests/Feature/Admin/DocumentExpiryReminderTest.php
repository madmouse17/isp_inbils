<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\User;
use App\Notifications\CustomerDocumentExpiringNotification;
use App\Services\Core\CompanyService;
use App\Services\Core\DocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-22 03:00:00 UTC');
        Storage::fake('local');
        Notification::fake();

        $this->company = Company::factory()->create(['is_active' => true, 'timezone' => 'Asia/Jakarta']);
        $this->recipient = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantPermissions($this->recipient, ['app.web', 'customer.update']);
        CompanyService::useCompanyForTests($this->company->id);
        $this->actingAs($this->recipient);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_command_notifies_customer_update_holders_once_and_writes_link_data(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'code' => 'CUS-7', 'name' => 'Asep Saepudin']);
        $type = DocumentType::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'KTP',
            'code' => 'KTP',
            'applies_to' => 'Customer',
            'expiry_days' => 30,
        ]);
        $media = DocumentService::upload($customer, UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf'), $type->code);

        $this->artisan('customers:document-expiry')->assertSuccessful()->expectsOutput('Sent 1 customer document expiry reminder(s).');
        $this->artisan('customers:document-expiry')->assertSuccessful()->expectsOutput('Sent 0 customer document expiry reminder(s).');

        Notification::assertSentToTimes($this->recipient, CustomerDocumentExpiringNotification::class, 1);
        Notification::assertSentTo($this->recipient, CustomerDocumentExpiringNotification::class, function (CustomerDocumentExpiringNotification $notification) use ($customer): bool {
            $data = $notification->toArray($this->recipient);

            return $data['company_id'] === $this->company->id
                && $data['customer_id'] === $customer->public_id
                && $data['customer_code'] === 'CUS-7'
                && $data['customer_name'] === 'Asep Saepudin'
                && $data['document_type'] === 'KTP'
                && $data['expires_at'] === '2026-11-21';
        });
        $this->assertSame('2026-11-21', $media->fresh()?->getCustomProperty('expiry_notified_for'));
    }

    public function test_command_ignores_expired_and_more_than_thirty_day_documents(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        DocumentService::upload($customer, UploadedFile::fake()->create('old.pdf', 100, 'application/pdf'), customProperties: ['expires_at' => '2026-10-21']);
        DocumentService::upload($customer, UploadedFile::fake()->create('later.pdf', 100, 'application/pdf'), customProperties: ['expires_at' => '2026-11-22']);

        $this->artisan('customers:document-expiry')->assertSuccessful()->expectsOutput('Sent 0 customer document expiry reminder(s).');
        Notification::assertNothingSent();
    }
}
