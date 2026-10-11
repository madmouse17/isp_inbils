<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialReturnRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $caseId,
        public readonly string $reason,
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
        return (new MailMessage())
            ->subject('Penolakan Serah Terima Retur Material: Kasus #'.$this->caseId)
            ->line('Serah terima fisik pengembalian material Anda ditolak oleh staf gudang.')
            ->line('ID Kasus Retur: #'.$this->caseId)
            ->line('Alasan Penolakan: '.$this->reason)
            ->line('Silakan periksa kembali fisik material atau perbaiki deklarasi retur Anda.')
            ->action('Lihat Detail Retur', url('/admin/inventory/returns/'.$this->caseId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_return_rejected',
            'case_id' => $this->caseId,
            'reason' => $this->reason,
            'company_id' => $this->companyId,
        ];
    }
}
