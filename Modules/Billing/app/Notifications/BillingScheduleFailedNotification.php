<?php

namespace Modules\Billing\Notifications;

use App\Models\User;
use App\Support\Notifications\StaffRecipients;
use App\Support\PermissionCatalogue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationSender;

class BillingScheduleFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $command,
        public readonly ?int $companyId = null,
        public readonly ?string $jobId = null,
    ) {}

    /**
     * The active administrators of one company (who hold `roles.manage` there, members of other companies included):
     * what went wrong for a tenant is told to that tenant's administrators and nobody else. The scheduler has nobody
     * signed in, so the company is named.
     *
     * @return Collection<int, User>
     */
    public static function recipients(int $companyId): Collection
    {
        return StaffRecipients::holding($companyId, PermissionCatalogue::ADMINISTRATION);
    }

    /**
     * One alert per failed company, each to its own admins, for a scheduled command that went on after a failure.
     *
     * @param  list<int>  $companyIds
     */
    public static function alertAdmins(string $command, array $companyIds): void
    {
        foreach ($companyIds as $companyId) {
            NotificationSender::send(self::recipients($companyId), new self(command: $command, companyId: $companyId));
        }
    }

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
