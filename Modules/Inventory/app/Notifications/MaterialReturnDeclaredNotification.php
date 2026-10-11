<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialReturnDeclaredNotification extends Notification implements ShouldQueue
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
            ->subject('Deklarasi Retur Material: Kasus #'.$this->caseId)
            ->line('Teknisi telah mendeklarasikan sisa material dan siap untuk serah terima fisik ke gudang.')
            ->line('ID Kasus Retur: #'.$this->caseId);

        if ($this->workOrderId) {
            $mail->line('Terkait SPK #'.$this->workOrderId);
        }

        return $mail->action('Lihat Detail Retur', url('/admin/inventory/returns/'.$this->caseId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_return_declared',
            'case_id' => $this->caseId,
            'work_order_id' => $this->workOrderId,
            'company_id' => $this->companyId,
        ];
    }
}
