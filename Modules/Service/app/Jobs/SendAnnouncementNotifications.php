<?php

namespace Modules\Service\Jobs;

use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Service\Models\Announcement;
use Modules\Service\Notifications\AnnouncementPublishedNotification;
use Modules\Service\Queries\CustomerAudienceQuery;

class SendAnnouncementNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $announcementId) {}

    public function uniqueId(): string
    {
        return (string) $this->announcementId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        $announcement = Announcement::withoutCompany()->find($this->announcementId);
        if ($announcement === null) {
            return;
        }

        CompanyService::runAs((int) $announcement->company_id, function () use ($announcement): void {
            $current = Announcement::query()->find($announcement->id);
            if ($current === null || $current->notified_at !== null || ! $current->is_active || $current->published_at === null
                || $current->starts_at->isFuture() || ! in_array($current->kind, ['outage', 'maintenance'], true)) {
                return;
            }

            $query = Customer::query()
                ->whereNotNull('user_id')
                ->whereHas('user', fn ($user) => $user->where('is_active', true));
            app(CustomerAudienceQuery::class)->applyCustomerAudience($query, $current)->chunkById(500, function ($customers) use ($current): void {
                $notified = DB::table('notifications')
                    ->where('notifiable_type', Customer::class)
                    ->where('type', AnnouncementPublishedNotification::class)
                    ->where('data->announcement_id', $current->public_id)
                    ->whereIn('notifiable_id', $customers->modelKeys())
                    ->pluck('notifiable_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($customers->whereNotIn('id', $notified) as $customer) {
                    $customer->notify(new AnnouncementPublishedNotification($current));
                }
            });

            Announcement::query()->whereKey($current)->whereNull('notified_at')->update(['notified_at' => now()]);
        });
    }
}
