<?php

namespace App\Notifications\Platform;

use App\Models\Core\Company;
use App\Models\User;
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
 * Tells an operator that a tenant asked for a subsidiary (K34). Sent on demand to each active platform admin, by the
 * platform mailer only (SentByPlatform): the request is about a tenant, so its own SMTP server and brand must not
 * carry it. It holds the company id and the request's public id and reads the text when it is sent, so what the
 * tenant typed never sits in the jobs table. The id also attributes a failed send to the tenant on its page.
 *
 * The tenant typed the name, the code and the note and may rename its company: Markdown renders every line of the
 * mail, so each of them goes through MailBrand::literal, and the note is one line so it cannot start a block.
 */
class SubsidiaryRequestedMail extends Notification implements SentByPlatform, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly int $companyId, public readonly string $requestId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Company::query()->findOrFail($this->companyId);
        $request = Activity::query()
            ->where('log_name', PlatformAuditService::PLATFORM_LOG)
            ->where('description', SubsidiaryRequestService::REQUESTED)
            ->where('company_id', $company->id)
            ->where('public_id', $this->requestId)
            ->firstOrFail();
        $text = fn (string $key): ?string => is_string($value = $request->properties?->get($key)) && Str::squish($value) !== '' ? Str::squish($value) : null;
        $companyName = Str::squish($company->name);
        $userId = $request->properties?->get('requested_by');
        $requester = is_int($userId) ? User::query()->whereKey($userId)->value('name') : null;

        $message = (new MailMessage())
            ->subject('Permintaan anak perusahaan dari '.$companyName)
            ->greeting('Permintaan anak perusahaan baru')
            ->line(MailBrand::literal($companyName).' (kode '.MailBrand::literal($company->code).') meminta anak perusahaan.');

        $details = [
            'Nama yang diminta' => $text('name'),
            'Kode usulan' => $text('code'),
            'Catatan' => $text('note'),
            'Diminta oleh' => is_string($requester) ? Str::squish($requester) : null,
        ];

        foreach (array_filter($details) as $label => $value) {
            $message->line($label.': '.MailBrand::literal($value));
        }

        return $message
            ->action('Tinjau permintaan', route('platform.companies.show', ['company' => $company, 'permintaan' => $this->requestId]))
            ->line('Permintaan tetap terbuka sampai ada yang membuat anak perusahaan atau menolaknya.');
    }
}
