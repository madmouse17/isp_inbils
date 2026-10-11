<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class CustomerAccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** The reset token is minted here, at send time, so it never sits in the jobs/failed_jobs payload. */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage())
            ->subject('Akun pelanggan Anda sudah dibuat')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Akun pelanggan Anda sudah dibuat. Atur kata sandi lewat tombol di bawah untuk masuk ke portal.')
            ->action('Atur kata sandi', $url)
            ->line('Tautan ini berlaku '.$minutes.' menit. Jika sudah kedaluwarsa, minta petugas kami mengirim ulang tautan akses.')
            ->line('Jika Anda tidak merasa mendaftar, abaikan email ini.');
    }
}
