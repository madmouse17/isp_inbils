<?php

namespace App\Notifications\Platform;

use App\Models\Core\Company;
use App\Notifications\Contracts\SentByPlatform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The invitation of a tenant's first admin. Platform provisioning created that account with a random password nobody
 * knows, so the set-password link in this mail is the way in. It goes out after the provisioning commits, so a
 * rolled-back tenant invites nobody. It carries the company id only and the reset token is minted at send time:
 * neither a name nor a token sits in the jobs/failed_jobs payload (the id also attributes a failed job to its tenant).
 */
class TenantAdminInvitation extends Notification implements SentByPlatform, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly int $companyId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Company::query()->findOrFail($this->companyId);
        $url = url(route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage())
            ->subject('Undangan admin '.$company->name.' di Inbils')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Anda diundang menjadi admin '.$company->name.' di Inbils. Atur kata sandi lewat tombol di bawah, lalu masuk dengan email ini.')
            ->action('Atur kata sandi', $url)
            ->line('Tautan ini berlaku '.$minutes.' menit. Jika sudah kedaluwarsa, minta tim Inbils mengirim ulang undangan.')
            ->line('Jika Anda tidak merasa diundang, abaikan email ini.');
    }
}
