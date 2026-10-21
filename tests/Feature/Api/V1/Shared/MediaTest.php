<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class MediaTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_owning_customer_streams_ticket_attachment_with_web_headers(): void
    {
        Storage::fake('local');
        [$user, $customer] = $this->customerLogin();
        $ticket = $this->ticketFor($customer);
        $file = $this->store($ticket, 'attachments', 'site-photo.png', base64_decode(self::PNG));

        $response = $this->api('GET', 'media/'.$file->public_id, token: $this->issueToken($user, 'customer'));

        $response->assertOk();
        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
        $this->assertSame($file->mime_type, $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_customer_gets_not_found_for_another_customers_attachment(): void
    {
        Storage::fake('local');
        [$user, $customer] = $this->customerLogin();
        [, $otherCustomer] = $this->customerLogin(Company::query()->findOrFail($customer->company_id));
        $file = $this->store($this->ticketFor($otherCustomer), 'attachments', 'site-photo.png', base64_decode(self::PNG));

        $this->api('GET', 'media/'.$file->public_id, token: $this->issueToken($user, 'customer'))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_technician_streams_allowed_spk_evidence(): void
    {
        Storage::fake('local');
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrderFor($technician);
        $file = $this->store($workOrder, 'evidence', 'proof.png', base64_decode(self::PNG));

        $response = $this->api('GET', 'media/'.$file->public_id, token: $this->issueToken($technician, 'technician'));

        $response->assertOk();
        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_dual_role_customer_token_cannot_fetch_spk_evidence_and_technician_token_only_assigned(): void
    {
        Storage::fake('local');
        $technician = $this->technicianLogin();
        $this->grantRole($technician, 'customer');
        Customer::factory()->create(['company_id' => $technician->company_id, 'user_id' => $technician->id]);
        $other = $this->technicianLogin($technician->company);
        $mine = $this->store($this->workOrderFor($technician), 'evidence', 'mine.png', base64_decode(self::PNG));
        $theirs = $this->store($this->workOrderFor($other), 'evidence', 'theirs.png', base64_decode(self::PNG));

        $this->api('GET', 'media/'.$mine->public_id, token: $this->issueToken($technician, 'customer'))->assertNotFound();

        $technicianToken = $this->issueToken($technician, 'technician');
        $this->api('GET', 'media/'.$mine->public_id, token: $technicianToken)->assertOk();
        $this->api('GET', 'media/'.$theirs->public_id, token: $technicianToken)->assertNotFound();
    }

    public function test_foreign_company_missing_file_and_disallowed_media_are_not_found(): void
    {
        Storage::fake('local');
        [$user, $customer] = $this->customerLogin();
        $foreignCompany = $this->apiCompany();
        $foreignCustomer = Customer::factory()->create(['company_id' => $foreignCompany->id]);
        $foreign = $this->store($this->ticketFor($foreignCustomer), 'attachments', 'foreign.png', base64_decode(self::PNG));
        $missing = $this->store($this->ticketFor($customer), 'attachments', 'missing.png', base64_decode(self::PNG));
        Storage::disk('local')->delete($missing->getPathRelativeToRoot());
        $spk = $this->store($this->workOrderFor($this->technicianLogin(Company::query()->findOrFail($customer->company_id))), 'evidence', 'spk.png', base64_decode(self::PNG));

        $token = $this->issueToken($user, 'customer');

        $this->api('GET', 'media/'.$foreign->public_id, token: $token)->assertNotFound();
        $this->api('GET', 'media/'.$missing->public_id, token: $token)->assertNotFound();
        $this->api('GET', 'media/'.$spk->public_id, token: $token)->assertNotFound();
        $this->api('GET', 'media/99999999', token: $token)->assertNotFound();
    }

    private function ticketFor(Customer $customer): Ticket
    {
        $category = TicketCategory::query()->create([
            'company_id' => $customer->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $customer->company_id,
            'code' => 'TKT-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => 'Media ticket',
            'description' => 'Attachment owner',
            'source' => 'customer',
            'category_id' => $category->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $customer->user_id ?? User::factory()->create(['company_id' => $customer->company_id])->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function workOrderFor(User $technician): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $technician->company_id,
            'assigned_to' => $technician->id,
            'status' => 'assigned',
            'created_by' => $technician->id,
        ]);
    }

    private function store(Model&HasMedia $owner, string $collection, string $name, string $bytes): Media
    {
        return $owner->addMediaFromString($bytes)
            ->usingFileName($name)
            ->withCustomProperties(['company_id' => $owner->getAttribute('company_id')])
            ->toMediaCollection($collection);
    }
}
