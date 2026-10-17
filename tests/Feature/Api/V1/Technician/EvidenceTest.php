<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class EvidenceTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_assigned_technician_uploads_evidence_with_captured_time(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $capturedAt = now()->subMinutes(5)->toIso8601String();

        $this->multipart($technician, "technician/work-orders/{$workOrder->public_id}/evidence", [
            'file' => UploadedFile::fake()->image('proof.jpg')->size(3072),
            'caption' => 'Before closing ODP',
            'captured_at' => $capturedAt,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.caption', 'Before closing ODP')
            ->assertJsonPath('data.captured_at', $capturedAt)
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.uploaded_by.name', $technician->name);

        $media = $workOrder->fresh()->getMedia('evidence')->sole();
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_invalid_uploads_are_422(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');

        foreach ([
            'too_large' => UploadedFile::fake()->image('large.jpg')->size(11264),
            'svg' => UploadedFile::fake()->createWithContent('bad.svg', '<svg><script>alert(1)</script></svg>'),
            'html' => UploadedFile::fake()->createWithContent('bad.html', '<html></html>'),
            'renamed_exe' => UploadedFile::fake()->createWithContent('proof.exe', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"),
        ] as $file) {
            $this->multipart($technician, "technician/work-orders/{$workOrder->public_id}/evidence", ['file' => $file])
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed');
        }

        $this->assertSame(0, $workOrder->fresh()->getMedia('evidence')->count());
    }

    public function test_idempotency_dedupe_survives_the_cache_window(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $key = (string) Str::uuid();
        $uri = "technician/work-orders/{$workOrder->public_id}/evidence";

        $first = $this->multipart($technician, $uri, ['file' => UploadedFile::fake()->image('proof.jpg')], $key)
            ->assertCreated();
        $id = $first->json('data.id');

        Cache::forget(EnforceIdempotency::cacheKey($technician->id, $key));

        $this->multipart($technician, $uri, ['file' => UploadedFile::fake()->image('proof-again.jpg')], $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertSame(1, $workOrder->fresh()->getMedia('evidence')->count());
    }

    public function test_evidence_upload_authorization_and_statuses(): void
    {
        $technician = $this->technicianLogin();
        $colleague = $this->technicianLogin($technician->company);

        $this->multipart($colleague, 'technician/work-orders/'.$this->workOrder($technician, 'in_progress')->public_id.'/evidence', [
            'file' => UploadedFile::fake()->image('bad.jpg'),
        ])->assertNotFound();

        $this->multipart($technician, 'technician/work-orders/'.$this->workOrder($technician, 'completed')->public_id.'/evidence', [
            'file' => UploadedFile::fake()->image('bad.jpg'),
        ])->assertForbidden();
    }

    public function test_uploader_removes_own_evidence_and_wrong_spk_is_404(): void
    {
        $technician = $this->technicianLogin();
        $colleague = $this->technicianLogin($technician->company);
        $workOrder = $this->workOrder($technician, 'in_progress');
        $other = $this->workOrder($technician, 'in_progress');
        $media = $this->evidence($workOrder, $technician);

        $this->api('DELETE', "technician/work-orders/{$other->public_id}/evidence/{$media->public_id}", token: $this->issueToken($technician, 'technician'))
            ->assertNotFound();
        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/evidence/{$media->public_id}", token: $this->issueToken($colleague, 'technician'))
            ->assertNotFound();
        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/evidence/{$media->public_id}", token: $this->issueToken($technician, 'technician'))
            ->assertNoContent();

        $this->assertNull(Media::query()->find($media->id));
    }

    private function workOrder(User $technician, string $status): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $technician->company_id,
            'status' => $status,
            'assigned_to' => $technician->id,
            'created_by' => $technician->id,
        ]);
    }

    private function evidence(WorkOrder $workOrder, User $uploader): Media
    {
        return $workOrder->addMediaFromString('proof')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $uploader->id])
            ->toMediaCollection('evidence');
    }

    /** @param array<string, mixed> $data */
    private function multipart(User $user, string $uri, array $data, ?string $key = null)
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        return $this->post('/api/v1/'.ltrim($uri, '/'), $data, [
            'Authorization' => 'Bearer '.$this->issueToken($user, 'technician'),
            'Accept' => 'application/json',
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }
}
