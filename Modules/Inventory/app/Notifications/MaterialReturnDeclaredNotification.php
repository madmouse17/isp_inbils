<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToReturnPage;

class MaterialReturnDeclaredNotification extends Notification implements ShouldQueue
{
    use LinksToReturnPage;
    use Queueable;

    public function __construct(
        public readonly int $caseId,
        public readonly ?int $workOrderId = null,
        public readonly ?int $companyId = null,
        public readonly ?int $inventoryRequestId = null,
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
            ->subject('Deklarasi Pengembalian Material: Kasus #'.$this->caseId)
            ->line('Teknisi sudah mendeklarasikan sisa material dan siap menyerahkannya ke gudang.')
            ->line('Nomor Kasus Pengembalian: #'.$this->caseId);

        if ($this->workOrderId) {
            $mail->line('Terkait SPK #'.$this->workOrderId);
        }

        return $mail->action('Lihat Kasus Pengembalian', $this->returnPageUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_return_declared',
            'case_id' => $this->caseId,
            'work_order_id' => $this->workOrderId,
            'company_id' => $this->companyId,
            'inventory_request_id' => $this->inventoryRequestId,
        ];
    }
}
