<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantExport>
 */
class TenantExportFactory extends Factory
{
    protected $model = TenantExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'requested_by_platform_admin_id' => PlatformAdmin::factory(),
            'status' => TenantExport::QUEUED,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => ['status' => TenantExport::RUNNING, 'started_at' => now()]);
    }

    /** A finished export whose archive is still downloadable. */
    public function ready(?string $completedAt = null): static
    {
        return $this->state(function (array $attributes) use ($completedAt): array {
            $completed = $completedAt === null ? now() : Carbon::parse($completedAt);

            return [
                'status' => TenantExport::READY,
                'file_path' => 'platform/exports/'.$attributes['uuid'].'.zip',
                'file_size' => 2048,
                'row_counts' => ['users' => 2, 'customers' => 3],
                'started_at' => $completed,
                'completed_at' => $completed,
                'expires_at' => $completed->copy()->addDay(),
            ];
        });
    }

    /** A finished export whose archive the cleanup already deleted. */
    public function expired(?string $completedAt = null): static
    {
        return $this->ready($completedAt)->state(fn (): array => ['status' => TenantExport::EXPIRED, 'file_path' => null, 'file_size' => null]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => TenantExport::FAILED, 'started_at' => now(), 'error_message' => 'ExportFailed: boom']);
    }
}
