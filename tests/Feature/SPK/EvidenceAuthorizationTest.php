<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Who may add and remove SPK evidence on the web: the assigned technician while the SPK is in progress, and a
 * holder of spk.update until it is completed or cancelled. Before this rule any technician of the company could
 * upload to, or delete from, any SPK in any status.
 */
class EvidenceAuthorizationTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private User $technician;

    private User $colleague;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = $this->apiCompany();
        $this->technician = $this->technicianLogin($this->company);
        $this->colleague = $this->technicianLogin($this->company);
        $this->manager = User::factory()->create(['company_id' => $this->company->id]);
        $this->manager->assignRole('manager');
    }

    public function test_a_technician_who_is_not_assigned_cannot_upload_to_an_in_progress_spk(): void
    {
        $workOrder = $this->workOrder('in_progress');

        $this->upload($this->colleague, $workOrder)->assertForbidden();

        $this->assertSame(0, $workOrder->fresh()->getMedia('evidence')->count());
    }

    #[DataProvider('closedStatuses')]
    public function test_nobody_uploads_to_a_closed_spk(string $status): void
    {
        $workOrder = $this->workOrder($status);

        $this->upload($this->technician, $workOrder)->assertForbidden();
        $this->upload($this->manager, $workOrder)->assertForbidden();

        $this->assertSame(0, $workOrder->fresh()->getMedia('evidence')->count());
    }

    /** @return array<string, array{0: string}> */
    public static function closedStatuses(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    public function test_the_assigned_technician_cannot_upload_once_the_spk_is_submitted(): void
    {
        $this->upload($this->technician, $this->workOrder('waiting_review'))->assertForbidden();
        $this->upload($this->technician, $this->workOrder('assigned'))->assertForbidden();
    }

    public function test_the_assigned_technician_uploads_while_the_spk_is_in_progress(): void
    {
        $workOrder = $this->workOrder('in_progress');

        $this->upload($this->technician, $workOrder, 'Router on the wall')->assertRedirect();

        $media = $workOrder->fresh()->getMedia('evidence')->sole();
        $this->assertSame($this->technician->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame('Router on the wall', $media->getCustomProperty('caption'));
        $this->assertSame('photo', $media->getCustomProperty('type'));
        $this->assertSame($this->company->id, (int) $media->getCustomProperty('company_id'));
    }

    #[DataProvider('openStatuses')]
    public function test_a_manager_with_spk_update_uploads_until_the_spk_is_completed(string $status): void
    {
        $workOrder = $this->workOrder($status);

        $this->upload($this->manager, $workOrder)->assertRedirect();

        $this->assertSame(1, $workOrder->fresh()->getMedia('evidence')->count());
    }

    /** @return array<string, array{0: string}> */
    public static function openStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'generated' => ['generated'],
            'assigned' => ['assigned'],
            'in_progress' => ['in_progress'],
            'waiting_review' => ['waiting_review'],
            'rejected' => ['rejected'],
        ];
    }

    public function test_a_user_from_another_company_gets_404(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $stranger = $this->technicianLogin();

        $this->upload($stranger, $workOrder)->assertNotFound();
    }

    public function test_uploading_is_recorded_in_the_audit_log_with_ids_only(): void
    {
        $workOrder = $this->workOrder('in_progress');

        $this->upload($this->technician, $workOrder, 'Private caption')->assertRedirect();

        $media = $workOrder->fresh()->getMedia('evidence')->sole();
        $row = DB::table('activity_log')->where('log_name', 'work_order')->where('description', 'evidence_uploaded')->sole();

        $this->assertSame($workOrder->id, (int) $row->subject_id);
        $this->assertSame($this->technician->id, (int) $row->causer_id);
        $properties = json_decode($row->properties, true);
        $this->assertSame($media->id, $properties['media_id']);
        $this->assertStringNotContainsString('Private caption', (string) $row->properties);
    }

    public function test_the_uploader_removes_their_own_evidence_while_in_progress(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $media = $this->evidence($workOrder, $this->technician);

        $this->remove($this->technician, $workOrder, $media)->assertRedirect();

        $this->assertNull(Media::query()->find($media->id));
        $removed = DB::table('activity_log')->where('log_name', 'work_order')->where('description', 'evidence_removed')->sole();
        $this->assertSame($media->id, json_decode($removed->properties, true)['media_id']);
    }

    public function test_a_colleague_cannot_remove_evidence_they_did_not_upload(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $media = $this->evidence($workOrder, $this->technician);

        $this->remove($this->colleague, $workOrder, $media)->assertForbidden();

        $this->assertNotNull(Media::query()->find($media->id));
    }

    public function test_the_assigned_technician_cannot_remove_evidence_a_manager_uploaded(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $media = $this->evidence($workOrder, $this->manager);

        $this->remove($this->technician, $workOrder, $media)->assertForbidden();
    }

    public function test_a_manager_with_spk_update_removes_evidence_before_completion(): void
    {
        $workOrder = $this->workOrder('waiting_review');
        $media = $this->evidence($workOrder, $this->technician);

        $this->remove($this->manager, $workOrder, $media)->assertRedirect();

        $this->assertNull(Media::query()->find($media->id));
    }

    #[DataProvider('closedStatuses')]
    public function test_evidence_of_a_closed_spk_stays(string $status): void
    {
        $workOrder = $this->workOrder($status);
        $media = $this->evidence($workOrder, $this->technician);

        $this->remove($this->technician, $workOrder, $media)->assertForbidden();
        $this->remove($this->manager, $workOrder, $media)->assertForbidden();

        $this->assertNotNull(Media::query()->find($media->id));
    }

    public function test_evidence_of_another_spk_is_404_and_stays(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $other = $this->workOrder('in_progress');
        $media = $this->evidence($other, $this->technician);

        $this->remove($this->technician, $workOrder, $media)->assertNotFound();

        $this->assertNotNull(Media::query()->find($media->id));
    }

    public function test_media_outside_the_evidence_collection_is_404(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $attachment = $workOrder->addMediaFromString('x')->usingFileName('x.txt')->toMediaCollection('documents');

        $this->remove($this->manager, $workOrder, $attachment)->assertNotFound();

        $this->assertNotNull(Media::query()->find($attachment->id));
    }

    public function test_the_show_page_offers_the_upload_only_to_those_who_may_use_it(): void
    {
        $workOrder = $this->workOrder('in_progress');

        $this->shown($this->technician, $workOrder)->assertInertia(fn (Assert $page) => $page->where('can.uploadEvidence', true));
        $this->shown($this->manager, $workOrder)->assertInertia(fn (Assert $page) => $page->where('can.uploadEvidence', true));
        $this->shown($this->colleague, $workOrder)->assertInertia(fn (Assert $page) => $page->where('can.uploadEvidence', false));

        $workOrder->update(['status' => 'waiting_review']);
        $this->shown($this->technician, $workOrder)->assertInertia(fn (Assert $page) => $page->where('can.uploadEvidence', false));

        $workOrder->update(['status' => 'completed']);
        $this->shown($this->manager, $workOrder)->assertInertia(fn (Assert $page) => $page->where('can.uploadEvidence', false));
    }

    private function workOrder(string $status): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'status' => $status,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->manager->id,
        ]);
    }

    private function upload(User $user, WorkOrder $workOrder, ?string $caption = null)
    {
        return $this->actingAs($user)->post(route('admin.spk.evidence.store', $workOrder), array_filter([
            'file' => UploadedFile::fake()->image('proof.jpg'),
            'caption' => $caption,
        ]));
    }

    private function remove(User $user, WorkOrder $workOrder, Media $media)
    {
        return $this->actingAs($user)->delete(route('admin.spk.evidence.destroy', [$workOrder, $media]));
    }

    private function shown(User $user, WorkOrder $workOrder)
    {
        return $this->actingAs($user)->get(route('admin.spk.show', $workOrder))->assertOk();
    }

    private function evidence(WorkOrder $workOrder, User $uploader): Media
    {
        return $workOrder->addMediaFromString('proof')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'caption' => null, 'uploaded_by' => $uploader->id])
            ->toMediaCollection('evidence');
    }
}
