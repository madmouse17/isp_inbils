<?php

namespace App\Notifications;

use App\Models\Core\Company;
use App\Notifications\Contracts\SentByPlatform;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/** Set-password invitation for a staff account created by its ISP administrator. */
class StaffAccountInvitation extends Notification implements SentByPlatform, ShouldQueueAfterCommit
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
            ->subject('Undangan staf '.$company->name.' di Inbils')
            ->greeting('Halo '.MailBrand::literal($notifiable->name).',')
            ->line('Anda diundang sebagai staf '.MailBrand::literal($company->name).' di Inbils. Atur kata sandi lewat tombol di bawah, lalu masuk dengan email ini.')
            ->action('Atur kata sandi', $url)
            ->line('Tautan ini berlaku '.$minutes.' menit. Jika sudah kedaluwarsa, minta admin ISP Anda mengirim ulang undangan.')
            ->line('Jika Anda tidak merasa diundang, abaikan email ini.');
    }
}
