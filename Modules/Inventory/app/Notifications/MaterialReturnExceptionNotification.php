<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Inventory\Notifications\Concerns\LinksToReturnPage;

class MaterialReturnExceptionNotification extends Notification implements ShouldQueue
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
        $records = $this->returnRecords();
        $mail = (new MailMessage())
            ->subject($this->returnSubject('Selisih Material Perlu Diselesaikan', $records))
            ->line('Gudang menemukan selisih (rusak, hilang, atau lainnya) saat memeriksa pengembalian material. Atasan yang berwenang perlu memutuskan penyelesaiannya.');

        return $this->returnReference($mail, $records)->action('Selesaikan Selisih', $this->returnPageUrl($records));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->returnData('material_return_exception', $this->returnRecords());
    }
}
