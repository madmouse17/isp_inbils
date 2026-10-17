<?php

namespace Tests\Feature\Api\V1\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Tests\TestCase;

/** Rows of the spec matrix that no endpoint-group test pins down: SPK submit by a non-assignee and technician ticket media. */
class FieldWorkGapsTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildWorld();
    }

    public function test_a_technician_who_is_not_the_assignee_cannot_submit_the_spk_and_nothing_changes(): void
    {
        $workOrder = WorkOrder::query()->wherePublicId($this->ids['work_order'])->firstOrFail();

        foreach ([$this->peerTechnician, $this->foreignTechnician] as $attacker) {
            $this->api('POST', "technician/work-orders/{$workOrder->public_id}/submit", token: $this->issueToken($attacker, 'technician'))
                ->assertNotFound();
        }

        $this->assertSame('in_progress', $workOrder->fresh()->status);
    }

    public function test_ticket_media_is_streamed_to_the_assigned_technician_only(): void
    {
        $ticket = Ticket::query()->wherePublicId($this->ids['technician_ticket'])->firstOrFail();
        $media = $ticket->addMediaFromString('png')->usingFileName('site.png')
            ->withCustomProperties(['company_id' => $ticket->company_id])->toMediaCollection('attachments');

        $this->api('GET', "media/{$media->public_id}", token: $this->technicianToken)->assertOk();

        $this->api('GET', "media/{$media->public_id}", token: $this->issueToken($this->peerTechnician, 'technician'))->assertNotFound();
        $this->api('GET', "media/{$media->public_id}", token: $this->issueToken($this->foreignTechnician, 'technician'))->assertNotFound();
        $this->api('GET', "media/{$media->public_id}", token: $this->customerToken)->assertNotFound();
    }
}
