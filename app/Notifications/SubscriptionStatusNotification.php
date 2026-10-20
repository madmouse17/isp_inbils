<?php

namespace App\Notifications;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Modules\Billing\Services\OverdueSuspensionService;

/**
 * In the bell, and on the phone when the app is installed, of the customer whose service was isolated or is served again:
 * without it they learn it from a dead connection. `$cause` is why it was isolated (`overdue` bills, a suspension by hand,
 * a `blocked` line); null means the service is back. It carries ids only; the subscription is read when the notice is
 * sent, inside its company, so an overdue notice names the bills that hold the service at that moment.
 */
class SubscriptionStatusNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @param 'overdue'|'manual'|'blocked'|null $cause */
    public function __construct(
        public readonly int $companyId,
        public readonly int $subscriptionId,
        public readonly ?string $cause,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return CompanyService::runAs($this->companyId, function (): array {
            $subscription = ServiceSubscription::query()->find($this->subscriptionId);

            $data = [
                'type' => $this->cause === null ? 'subscription_reactivated' : 'subscription_isolated',
                'subscription_id' => $subscription?->public_id,
                'subscription_code' => $subscription?->code,
            ];

            if ($this->cause === null) {
                return $data;
            }

            $data['cause'] = $this->cause;

            if ($this->cause === 'overdue') {
                $data['invoice_numbers'] = $subscription === null ? [] : OverdueSuspensionService::holdingInvoices($subscription)->pluck('number')->all();
            } else {
                $data['company_name'] = Company::query()->whereKey($this->companyId)->value('name');
            }

            return $data;
        });
    }
}
