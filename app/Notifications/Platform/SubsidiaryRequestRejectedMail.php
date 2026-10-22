<?php

namespace App\Notifications\Platform;

use App\Models\Core\Company;
use App\Notifications\Contracts\SentByPlatform;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\SubsidiaryRequestService;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Tells the admin who asked for a subsidiary (K34) that the operator turned the request down, and why. The platform
 * answers, so it leaves from the platform mailer (SentByPlatform) under the Inbils name. It holds the company id and
 * the public id of the closing audit row and reads the name and the operator's reason when it is sent, so neither
 * sits in the jobs table; the company id also attributes a failed send to the tenant on its page.
 *
 * The name was typed by the tenant and the reason by an operator: both go through MailBrand::literal, and the
 * reason is one line so it cannot start a Markdown block.
 */
class SubsidiaryRequestRejectedMail extends Notification implements SentByPlatform, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly int $companyId, public readonly string $closureId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Company::query()->findOrFail($this->companyId);
        $closing = Activity::query()
            ->where('log_name', PlatformAuditService::PLATFORM_LOG)
            ->where('description', SubsidiaryRequestService::CLOSED)
            ->where('company_id', $company->id)
            ->where('public_id', $this->closureId)
            ->firstOrFail();
        $text = fn (string $key): string => is_string($value = $closing->properties?->get($key)) ? Str::squish($value) : '';
        $name = $text('name');

        return (new MailMessage())
            ->subject('Permintaan anak perusahaan ditolak')
            ->greeting('Halo '.MailBrand::literal(Str::squish((string) data_get($notifiable, 'name'))).',')
            ->line('Permintaan anak perusahaan'.($name === '' ? '' : ' '.MailBrand::literal($name)).' untuk '.MailBrand::literal(Str::squish($company->name)).' tidak disetujui operator.')
            ->line('Alasan: '.MailBrand::literal($text('reason') ?: '-'))
            ->line('Sesuaikan permintaannya, lalu ajukan lagi dari halaman Perusahaan. Hubungi tim Inbils bila alasan di atas belum jelas.');
    }
}
