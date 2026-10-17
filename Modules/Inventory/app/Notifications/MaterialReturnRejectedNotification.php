<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToReturnPage;

class MaterialReturnRejectedNotification extends Notification implements ShouldQueue
{
    use LinksToReturnPage;
    use Queueable;

    public function __construct(
        public readonly int $caseId,
        public readonly string $reason,
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
        $records = $this->returnRecords();
        $mail = (new MailMessage())
            ->subject($this->returnSubject('Penyerahan Pengembalian Material Ditolak', $records))
            ->line('Gudang menolak penyerahan fisik pengembalian material Anda.');

        return $this->returnReference($mail, $records)
            ->line('Alasan Penolakan: '.$this->reason)
            ->line('Periksa kembali barang fisik atau perbaiki deklarasi pengembalian Anda.')
            ->action('Lihat Kasus Pengembalian', $this->returnPageUrl($records));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [...$this->returnData('material_return_rejected', $this->returnRecords()), 'reason' => $this->reason];
    }
}
