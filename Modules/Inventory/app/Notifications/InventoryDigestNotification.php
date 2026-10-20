<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one mail the warehouse sends, once a day and only to people who switched it on in their profile: what waits for them,
 * counted per company they work in. The events themselves live in the bell; this is the morning summary of them.
 */
class InventoryDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{company: string, lines: list<array{label: string, count: int}>}>  $companies  by company id; a company with nothing waiting is not here
     */
    public function __construct(public readonly array $companies) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = collect($this->companies)->sum(fn (array $company): int => array_sum(array_column($company['lines'], 'count')));
        $mail = (new MailMessage())
            ->subject("Ringkasan Harian Gudang: {$total} menunggu Anda")
            ->line('Ini yang menunggu tindakan Anda hari ini.');

        foreach ($this->companies as $company) {
            // The company is named only when there is more than one to tell apart.
            if (count($this->companies) > 1) {
                $mail->line("**{$company['company']}**");
            }

            foreach ($company['lines'] as $line) {
                $mail->line("{$line['label']}: {$line['count']}");
            }
        }

        return $mail
            ->action('Buka Permintaan Barang', route('admin.inventory.requests.index'))
            ->line('Anda menerima email ini karena mengaktifkan ringkasan harian di Profil. Matikan kapan saja di Profil, bagian Notifikasi email.');
    }
}
