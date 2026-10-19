<?php

namespace App\Notifications;

use App\Models\Core\Customer;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Contracts\SentByPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The set-password mail of a customer account. For a customer's portal user it is followed by a WhatsApp notice
 * that the link is in their e-mail, never by the link itself: Laravel keeps one reset token per user and every
 * mint deletes the previous one, so a second channel minting its own would break the e-mailed link, and a number
 * on file can belong to someone else by now. The notice goes out only with the customer's WhatsApp consent.
 *
 * Platform mailer only (SentByPlatform): nothing stops the CLI (`inbils:company:grant-membership`) from giving this user a second company.
 */
class CustomerAccountCreated extends WhatsAppNotification implements SentByPlatform
{
    /** The hour of the notice's key, fixed when it is queued: a retry in a later hour takes over its first attempt's row. */
    private readonly string $hour;

    public function __construct(int $companyId)
    {
        parent::__construct($companyId);

        $this->hour = now()->format('Y-m-d-H');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // Without the company scope: the legacy password rotation runs without a company context.
        return Customer::withoutCompany()->where('user_id', $notifiable->getKey())->exists()
            ? ['mail', WhatsAppChannel::class]
            : ['mail'];
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
            ->line('Akun pelanggan Anda sudah dibuat. Atur kata sandi lewat tombol di bawah, lalu masuk di aplikasi Inbils Pelanggan dengan email ini.')
            ->action('Atur kata sandi', $url)
            ->line('Tautan ini berlaku '.$minutes.' menit. Jika sudah kedaluwarsa, minta petugas kami mengirim ulang tautan akses.')
            ->line('Jika Anda tidak merasa mendaftar, abaikan email ini.');
    }

    /** At most one notice per user and hour (of queuing), however often staff resend the link. No URL, no token. */
    public function toWhatsApp(object $notifiable): ?array
    {
        return $this->payload(
            'access_link_notice',
            [$this->companyName(), Str::substr((string) $notifiable->email, 0, 1).'***@'.Str::after((string) $notifiable->email, '@')],
            'user',
            $notifiable instanceof Model ? $notifiable : null,
            'access_link_notice:'.$notifiable->getKey().':'.$this->hour,
        );
    }

    protected function event(): string
    {
        return 'access_link_notice';
    }
}
