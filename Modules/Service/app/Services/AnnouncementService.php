<?php

namespace Modules\Service\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Service\Models\Announcement;

class AnnouncementService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Announcement
    {
        return DB::transaction(function () use ($data): Announcement {
            $announcement = Announcement::query()->create($this->attributes($data));
            $this->syncTargets($announcement, $data);
            AuditService::log('announcement', 'created', ['announcement_id' => $announcement->id], $announcement);
            HomeFeedCache::flush((int) $announcement->company_id);

            return $announcement;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Announcement $announcement, array $data): Announcement
    {
        return DB::transaction(function () use ($announcement, $data): Announcement {
            $announcement->update($this->attributes($data));
            $this->syncTargets($announcement, $data);
            AuditService::log('announcement', 'updated', ['announcement_id' => $announcement->id], $announcement);
            HomeFeedCache::flush((int) $announcement->company_id);

            return $announcement;
        });
    }

    public function publish(Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($announcement): Announcement {
            $locked = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($locked->published_at !== null) {
                throw ValidationException::withMessages(['status' => 'Pengumuman sudah diterbitkan.']);
            }
            if ($locked->ends_at !== null && ! $locked->ends_at->isFuture()) {
                throw ValidationException::withMessages(['ends_at' => 'Periode pengumuman sudah berakhir.']);
            }

            $locked->forceFill(['published_at' => now(), 'is_active' => true])->save();
            $announcement->setRawAttributes($locked->getAttributes(), true);
            AuditService::log('announcement', 'published', ['announcement_id' => $announcement->id], $announcement);
            HomeFeedCache::flush((int) $announcement->company_id);

            if (in_array($announcement->kind, ['outage', 'maintenance'], true) && ! $announcement->starts_at->isFuture()) {
                SendAnnouncementNotifications::dispatch((int) $announcement->id)->afterCommit();
            }

            return $announcement;
        });
    }

    public function deactivate(Announcement $announcement): Announcement
    {
        abort_if($announcement->published_at === null, 422, 'Draf tidak perlu dinonaktifkan.');

        $announcement->forceFill(['is_active' => false])->save();
        AuditService::log('announcement', 'deactivated', ['announcement_id' => $announcement->id], $announcement);
        HomeFeedCache::flush((int) $announcement->company_id);

        return $announcement;
    }

    public function deleteDraft(Announcement $announcement): void
    {
        if ($announcement->published_at !== null) {
            throw ValidationException::withMessages(['status' => 'Pengumuman terbit hanya dapat dinonaktifkan.']);
        }

        DB::transaction(function () use ($announcement): void {
            AuditService::log('announcement', 'deleted', ['announcement_id' => $announcement->id], $announcement);
            $companyId = (int) $announcement->company_id;
            $announcement->delete();
            HomeFeedCache::flush($companyId);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $timezone = CompanyService::current()?->timezone ?: (string) config('app.timezone');
        $storageTimezone = (string) config('app.timezone');
        $attributes = Arr::only($data, ['title', 'body', 'kind', 'audience']);
        $attributes['starts_at'] = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $data['starts_at'], $timezone)->setTimezone($storageTimezone);
        $attributes['ends_at'] = filled($data['ends_at'] ?? null)
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $data['ends_at'], $timezone)->setTimezone($storageTimezone)
            : null;

        return $attributes;
    }

    /** @param array<string, mixed> $data */
    private function syncTargets(Announcement $announcement, array $data): void
    {
        $announcement->locations()->sync($announcement->audience === 'targeted' ? ($data['location_ids'] ?? []) : []);
        $announcement->servicePackageCategories()->sync($announcement->audience === 'targeted' ? ($data['service_package_category_ids'] ?? []) : []);
    }
}
