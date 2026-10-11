<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialReturnExceptionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $caseId,
        public readonly ?int $workOrderId = null,
        public readonly ?int $companyId = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('Pengecualian Retur Material Terbuka: Kasus #'.$this->caseId)
            ->line('Ditemukan selisih (discrepancy/damaged/lost) pada serah terima retur material yang membutuhkan resolusi atasan/resolver.')
            ->line('ID Kasus Retur: #'.$this->caseId);

        if ($this->workOrderId) {
            $mail->line('Terkait SPK #'.$this->workOrderId);
        }

        return $mail->action('Selesaikan Pengecualian', url('/admin/inventory/returns/'.$this->caseId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_return_exception',
            'case_id' => $this->caseId,
            'work_order_id' => $this->workOrderId,
            'company_id' => $this->companyId,
        ];
    }
}
