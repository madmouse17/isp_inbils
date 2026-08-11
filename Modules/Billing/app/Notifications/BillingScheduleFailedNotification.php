<?php

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingScheduleFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $command,
        public readonly ?int $companyId = null,
        public readonly ?string $jobId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Billing schedule failed: '.$this->command)
            ->line('A billing scheduled command failed.')
            ->line('Command: '.$this->command)
            ->line('Company ID: '.($this->companyId ?? 'n/a'))
            ->line('Job/Request ID: '.($this->jobId ?? 'n/a'))
            ->line('Check application logs for details. No stack trace included.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'billing_schedule_failed',
            'command' => $this->command,
            'company_id' => $this->companyId,
            'job_id' => $this->jobId,
        ];
    }
}
