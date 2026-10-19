<?php

namespace Tests\Feature\SPK;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * Evidence is stored once per file and caption on an SPK, however far apart two uploads are, and an upload that is
 * rolled back leaves neither a row nor a file.
 */
class WorkOrderEvidenceIdempotencyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    /** A 1x1 grey PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $admin;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->admin = $this->createCompanyUser();
        $this->workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'type' => 'maintenance',
            'status' => 'in_progress',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_the_same_file_uploaded_twice_from_the_web_is_stored_once(): void
    {
        // The 10-second duplicate guard is on, and the second upload comes after it: the key alone makes it one row.
        config(['security.duplicate_submit.enabled' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.spk.evidence.store', $this->workOrder), ['file' => $this->png(), 'caption' => 'Foto kabel'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Bukti berhasil diunggah.');
        $this->travel(11)->seconds();
        $this->post(route('admin.spk.evidence.store', $this->workOrder), ['file' => $this->png(), 'caption' => 'Foto kabel'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Bukti berhasil diunggah.');

        $evidence = $this->workOrder->fresh()->getMedia('evidence');
        $this->assertCount(1, $evidence);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->first()->getCustomProperty('idempotency_key'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_same_file_with_another_caption_or_another_file_is_new_evidence(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.spk.evidence.store', $this->workOrder), ['file' => $this->png(), 'caption' => 'Foto kabel'])
            ->assertRedirect();
        $this->post(route('admin.spk.evidence.store', $this->workOrder), ['file' => $this->png(), 'caption' => 'Foto ODP'])->assertRedirect();
        $this->post(route('admin.spk.evidence.store', $this->workOrder), [
            'file' => UploadedFile::fake()->createWithContent('lain.png', base64_decode(self::PNG).'extra'),
            'caption' => 'Foto kabel',
        ])->assertRedirect();

        $evidence = $this->workOrder->fresh()->getMedia('evidence');
        $this->assertCount(3, $evidence);
        $this->assertCount(3, $evidence->map(fn ($media) => $media->getCustomProperty('idempotency_key'))->unique());
    }

    public function test_a_rolled_back_evidence_upload_leaves_no_file(): void
    {
        Activity::creating(fn () => throw new RuntimeException('audit store down'));

        try {
            SpkService::addEvidence($this->workOrder, $this->admin, ['file' => $this->png(), 'caption' => null]);
            $this->fail('The failing audit write must roll the upload back.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit store down', $e->getMessage());
        } finally {
            Activity::flushEventListeners();
        }

        $this->assertCount(0, $this->workOrder->fresh()->getMedia('evidence'));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the file of the rolled-back upload is gone');
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bukti.png', base64_decode(self::PNG));
    }
}
