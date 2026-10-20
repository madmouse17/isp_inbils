<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * In the bell of the staff who may isolate a subscription: the daily run of `billing:suspend-overdue` isolated `$count`
 * subscriptions of the company for unpaid bills. One row per run, never one per subscription; no push, so a daily batch
 * does not wake a phone. It names no record: the bell links to the Tunggakan page.
 */
class SubscriptionsIsolatedSummaryNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $count,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'subscriptions_isolated_summary', 'count' => $this->count, 'company_id' => $this->companyId];
    }
}
