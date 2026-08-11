<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketAttachmentSharedUploadTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_ticket_attachment_uses_shared_upload_concern(): void
    {
        Storage::fake('public');

        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $ticket = $this->ticket($user);
        $file = UploadedFile::fake()->create('ticket-note.txt', 12, 'text/plain');

        $this->post(route('admin.tickets.attachments.store', $ticket), ['file' => $file])
            ->assertRedirect();

        $media = $ticket->fresh()->getMedia('attachments')->first();

        $this->assertNotNull($media);
        $this->assertSame((string) $user->company_id, (string) $media->getCustomProperty('company_id'));
        $this->assertSame($user->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame('attachments', $media->collection_name);
        $this->assertSame('public', $media->disk);
    }

    public function test_work_order_evidence_still_uses_shared_upload_concern(): void
    {
        Storage::fake('public');

        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $workOrder = $this->workOrder($user);
        $file = UploadedFile::fake()->image('spk-evidence.jpg');

        $this->post(route('admin.spk.evidence.store', $workOrder), [
            'file' => $file,
            'caption' => 'Field photo',
        ])->assertRedirect();

        $media = $workOrder->fresh()->getMedia('evidence')->first();

        $this->assertNotNull($media);
        $this->assertSame((string) $user->company_id, (string) $media->getCustomProperty('company_id'));
        $this->assertSame($user->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame('photo', $media->getCustomProperty('type'));
        $this->assertSame('Field photo', $media->getCustomProperty('caption'));
        $this->assertSame('evidence', $media->collection_name);
        $this->assertSame('public', $media->disk);
    }

    private function ticket(User $user): Ticket
    {
        $category = TicketCategory::query()->create([
            'company_id' => $user->company_id,
            'name' => 'Support',
            'code' => 'TKT-'.fake()->unique()->numberBetween(1000, 9999),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $user->company_id,
            'code' => 'TKT-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => 'Attachment ticket',
            'description' => 'Upload coverage',
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $user->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function workOrder(User $user): WorkOrder
    {
        return WorkOrder::query()->create([
            'company_id' => $user->company_id,
            'code' => 'SPK-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'type' => 'maintenance',
            'title' => 'Evidence SPK',
            'description' => 'Upload coverage',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $user->id,
        ]);
    }
}
