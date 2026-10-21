<?php

namespace App\Support;

use App\Models\Core\Company;
use App\Notifications\Contracts\SentByPlatform;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Notification;

/**
 * Whose name a notification mail carries (header, footer, signature, From name): the ISP's for an ordinary mail, the
 * platform's for a mail the platform sends (credential links, see SentByPlatform).
 */
final class MailBrand
{
    /**
     * A mail that carries a credential, sent by the platform and never from a company's own SMTP server. Laravel's own
     * reset and verification mails are named here; the notifications of the app say so with SentByPlatform.
     */
    public static function sentByPlatform(Notification $notification): bool
    {
        return $notification instanceof SentByPlatform || $notification instanceof ResetPassword || $notification instanceof VerifyEmail;
    }

    /**
     * The company a mail is sent for: the one the notification names, else the one in scope, else the home company of its
     * recipient.
     */
    public static function companyId(object $notifiable, Notification $notification): ?int
    {
        $id = data_get($notification, 'companyId') ?? CompanyService::currentId() ?? data_get($notifiable, 'company_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A name or title typed by a user, as a line of a mail: Markdown renders the lines, so a title such as `[Pay now](https://evil.test)`
     * would become a link the platform's own mail vouches for. HTML is already escaped by Blade.
     */
    public static function literal(string $text): string
    {
        return (string) preg_replace('/[\\\\`*_\[\]#|~+-]/', '\\\\$0', $text);
    }

    /**
     * The logo is the company's public one; a company whose logo is not on the public disk gets its name only, because a
     * mail client cannot sign in to fetch a private file.
     *
     * @return array{name: string, logo_url: ?string}
     */
    public static function for(object $notifiable, Notification $notification): array
    {
        $platform = ['name' => 'Inbils', 'logo_url' => null];

        if (self::sentByPlatform($notification)) {
            return $platform;
        }

        $companyId = self::companyId($notifiable, $notification);
        $company = $companyId === null ? null : Company::query()->find($companyId);

        if ($company === null) {
            return $platform;
        }

        $logo = $company->getFirstMedia('logo');

        return ['name' => $company->name, 'logo_url' => $logo?->disk === 'public' ? $logo->getFullUrl() : null];
    }
}
