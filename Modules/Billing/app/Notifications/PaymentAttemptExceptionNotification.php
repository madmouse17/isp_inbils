<?php

namespace Modules\Billing\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\PaymentAttempt;

/**
 * In the bell and on the phone of billing staff: an online payment reached Inbils and could not be booked on its own (the
 * invoice was paid or cancelled meanwhile, the amount differs, Midtrans took the money back), so a person has to decide.
 * It carries ids only; the row is read when it is sent, inside the attempt's company.
 */
class PaymentAttemptExceptionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $attemptId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $attempt = CompanyService::runAs(
            $this->companyId,
            fn (): PaymentAttempt => PaymentAttempt::query()->with('invoice:id,public_id,number')->findOrFail($this->attemptId),
        );

        return [
            'type' => 'payment_attempt_exception',
            'invoice_id' => $attempt->invoice->public_id,
            'invoice_number' => $attempt->invoice->number,
            'order_id' => $attempt->order_id,
            'amount' => $attempt->amount,
            'reason' => $attempt->status_reason,
            'company_id' => $this->companyId,
        ];
    }
}
