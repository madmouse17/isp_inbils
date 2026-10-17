<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use Illuminate\Support\Facades\DB;

/**
 * The rows the platform tenant list and the tenant detail page read for their metrics: media (storage), failed jobs
 * (attributed by the `companyId` the job carries) and failed WhatsApp messages. Written straight to the tables, with
 * the shapes production writes them in.
 */
trait BuildsTenantMetrics
{
    protected function media(?int $companyId, int $size): void
    {
        DB::table('media')->insert([
            'model_type' => Company::class,
            'model_id' => $companyId ?? 0,
            'uuid' => (string) fake()->uuid(),
            'collection_name' => 'logo',
            'name' => 'file',
            'file_name' => 'file.webp',
            'mime_type' => 'image/webp',
            'disk' => 'local',
            'conversions_disk' => 'local',
            'size' => $size,
            'manipulations' => '[]',
            'custom_properties' => json_encode($companyId === null ? [] : ['company_id' => $companyId]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return int the id of the failed job row */
    protected function failedJob(?int $companyId, mixed $failedAt = null, string $exception = 'boom'): int
    {
        $command = $companyId === null ? 'O:8:"stdClass":0:{}' : 'O:8:"stdClass":1:{s:9:"companyId";i:'.$companyId.';}';

        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) fake()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob', 'data' => ['command' => $command]]),
            'exception' => $exception,
            'failed_at' => $failedAt ?? now(),
        ]);
    }

    /** @return int the id of the message row */
    protected function failedWhatsApp(int $companyId, string $status, mixed $failedAt): int
    {
        return DB::table('whatsapp_messages')->insertGetId([
            'company_id' => $companyId,
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'event' => 'access_link_notice',
            'template' => 't',
            'language' => 'id',
            'dedupe_key' => fake()->unique()->uuid(),
            'status' => $status,
            'error_code' => $status === 'failed' ? '131026' : null,
            'recipient_last4' => '7890',
            'failed_at' => $failedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
