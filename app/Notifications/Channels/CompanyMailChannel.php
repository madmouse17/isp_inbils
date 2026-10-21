<?php

namespace App\Notifications\Channels;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Services\Core\IntegrationConfig;
use App\Support\MailBrand;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The mail channel of the app (bound over Laravel's in AppServiceProvider): a company with its own enabled e-mail
 * integration sends through its own SMTP server and sender; every other mail goes through the platform mailer, as before.
 * So does every mail that carries a credential (a reset or invitation link): see mailerOf().
 *
 * The mailer is built on every send and never kept: a channel instance lives as long as a queue worker, and a kept mailer
 * would go on using the account of a row that was saved meanwhile.
 */
class CompanyMailChannel extends MailChannel
{
    /**
     * Replaces the parent's body instead of calling it: toMail() runs exactly once, because a notification may mint
     * something there (CustomerAccountCreated mints a password reset token, and a second mint would void the first).
     *
     * shortcut: a queued notification that carries no `companyId` is sent for the company of its recipient's home, so a
     * user with several memberships may be mailed from the home company's sender; give such a notification a companyId.
     */
    public function send($notifiable, Notification $notification)
    {
        // The mail channel only serves notifications that define toMail(), as Laravel's own channel assumes.
        $message = $notification->toMail($notifiable); // @phpstan-ignore method.notFound

        if (! $notifiable->routeNotificationFor('mail', $notification) && ! $message instanceof Mailable) {
            return null;
        }

        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }

        $brand = MailBrand::for($notifiable, $notification);

        return $this->mailerOf($notifiable, $notification, $message, $brand)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification), ['brand' => $brand]),
            $this->messageBuilder($notifiable, $notification, $message),
        );
    }

    /**
     * A mailer on the company's own SMTP account, with its sender on every message. The host policy is the caller's:
     * mailerOf() applies it for a send, TestCompanyIntegration for "Uji koneksi".
     */
    public static function mailerFor(CompanyIntegration $row): Mailer
    {
        // The `array` and `log` transports are for tests and tinker; the screen never writes the key.
        $transport = $row->setting('transport');

        $mailer = Mail::build(in_array($transport, ['array', 'log'], true) && app()->environment(['local', 'testing', 'e2e'])
            ? ['transport' => $transport]
            : [
                'transport' => 'smtp',
                'scheme' => $row->setting('scheme', 'smtp'),
                'host' => $row->setting('host'),
                'port' => $row->setting('port', 587),
                'username' => $row->setting('username'),
                'password' => $row->secret('password'),
                'timeout' => 5,
                'local_domain' => config('mail.mailers.smtp.local_domain'),
            ]);

        $mailer->alwaysFrom($row->setting('from_address'), $row->setting('from_name'));

        return $mailer;
    }

    /**
     * "Uji koneksi" of the e-mail card: one test mail to the admin who clicked. The host policy is applied by the caller
     * (TestCompanyIntegration) before this dials the host the row names.
     *
     * @return string|null the Indonesian reason it failed, null when the server accepted the message
     */
    public static function check(CompanyIntegration $row, User $to): ?string
    {
        if (! filled($row->setting('host')) || ! filled($row->setting('from_address'))) {
            return 'Isi host SMTP dan alamat pengirim dulu.';
        }

        $company = Company::query()->whereKey($row->company_id)->value('name');

        try {
            self::mailerFor($row)->raw(
                "Email uji dari Inbils untuk {$company}. Bila email ini sampai, pengaturan SMTP sudah benar.",
                fn ($message) => $message->to($to->email)->subject('Email uji Inbils'),
            );
        } catch (TransportExceptionInterface $e) {
            return self::failureMessage($e, $row);
        }

        return null;
    }

    /**
     * What a failed SMTP exchange means to the admin. Reads the exception, never repeats it: its text can quote the
     * user name or the server's reply.
     */
    public static function failureMessage(TransportExceptionInterface $e, CompanyIntegration $row): string
    {
        $text = $e->getMessage();
        $code = (int) $e->getCode();

        return match (true) {
            $code === 535 || str_contains($text, 'Failed to authenticate') => 'Login SMTP ditolak. Periksa nama pengguna dan kata sandi.',
            preg_match('/STARTTLS|TLS required|SSL operation failed|SSL routines|OpenSSL/i', $text) === 1 => 'Gagal membuka TLS ke server SMTP. Periksa enkripsi dan port.',
            // Only MAIL FROM expects the single code 250 (RCPT TO accepts 250, 251 and 252).
            in_array($code, [550, 553], true) && str_contains($text, 'Expected response code "250" but') => 'Server SMTP menolak alamat pengirim '.$row->setting('from_address').'.',
            $code > 0 => "Email uji gagal: server SMTP menjawab kode {$code}.",
            default => 'Server SMTP tidak dapat dihubungi dalam 5 detik ('.$row->setting('host').':'.$row->setting('port', 587).').',
        };
    }

    /**
     * The mailer of one message: the one it names itself, else the platform's for a mail that carries a credential, else
     * the company's own when that has an enabled e-mail integration, else the platform default.
     *
     * A user can belong to several companies, so the operator of one company's mail server must never read a link that
     * opens an account of another: Laravel's own reset and verification mails are named here, and the notifications of
     * the app that mint such a link say so with SentByPlatform.
     *
     * A company's host that the policy refuses behaves as no integration at all (CompanyIntegration::allowsConnection).
     *
     * @param  array{name: string, logo_url: ?string}  $brand
     */
    private function mailerOf(object $notifiable, Notification $notification, MailMessage $message, array $brand): MailerContract
    {
        if ($message->mailer !== null) {
            return $this->mailer->mailer($message->mailer);
        }

        if (MailBrand::sentByPlatform($notification)) {
            return $this->mailer->mailer();
        }

        $companyId = MailBrand::companyId($notifiable, $notification);
        $row = $companyId === null ? null : app(IntegrationConfig::class)->forCompany($companyId, 'mail');

        if ($row?->is_enabled === true && $row->allowsConnection()) {
            return self::mailerFor($row);
        }

        // The platform's address and mail server, the ISP's name: the customer sees who wrote, the DNS records stay the platform's.
        if (empty($message->from)) {
            $message->from((string) config('mail.from.address'), $brand['name']);
        }

        return $this->mailer->mailer();
    }
}
