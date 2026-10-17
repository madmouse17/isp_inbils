<?php

namespace Modules\Service\Console;

use Illuminate\Console\Command;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Service\Models\Announcement;

class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:publish-scheduled';

    protected $description = 'Dispatch notifications for due outage and maintenance announcements';

    public function handle(): int
    {
        Announcement::withoutCompany()
            ->whereNotNull('published_at')
            ->where('is_active', true)
            ->whereIn('kind', ['outage', 'maintenance'])
            ->whereNull('notified_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('id')
            ->chunkById(500, fn ($announcements) => $announcements->each(
                fn (Announcement $announcement) => SendAnnouncementNotifications::dispatch((int) $announcement->id),
            ));

        return self::SUCCESS;
    }
}
