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
            ->subject('Jadwal penagihan gagal: '.$this->command)
            ->line('Perintah terjadwal penagihan gagal dijalankan.')
            ->line('Perintah: '.$this->command)
            ->line('ID perusahaan: '.($this->companyId ?? '-'))
            ->line('ID job/permintaan: '.($this->jobId ?? '-'))
            ->line('Periksa log aplikasi untuk detailnya. Stack trace tidak disertakan.');
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
