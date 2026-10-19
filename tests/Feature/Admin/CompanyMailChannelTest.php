<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Notifications\Channels\CompanyMailChannel;
use App\Notifications\Contracts\SentByPlatform;
use App\Notifications\CustomerAccountCreated;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * The mail of every notification goes through the company's own SMTP account when it has an enabled e-mail integration,
 * and through the platform mailer otherwise: always the platform's for a mail that carries a credential link (a user can
 * belong to several companies), and for a host the host policy refuses. The companies here use the `array` transport
 * (a row's `settings.transport`, honoured in tests and local only), and a sent message is told apart by its sender and
 * by the mailer that sent it.
 */
class CompanyMailChannelTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    /** @var list<MessageSent> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        config(['mail.from.address' => 'platform@inbils.test', 'mail.from.name' => 'Inbils']);
        CompanyMailProbe::$toMailCalls = 0;
        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $this->sent[] = $event;
        });
    }

    /** @param array<string, mixed> $over */
    private function mailRow(Company $company, array $over = []): CompanyIntegration
    {
        return CompanyIntegration::query()->create([
            'company_id' => $company->id,
            'provider' => 'mail',
            'is_enabled' => true,
            'credentials' => ['password' => 'smtp-secret-sentinel-0001'],
            'settings' => ['transport' => 'array', 'host' => '10.0.0.5', 'port' => 587, 'from_address' => "billing@{$company->code}.test", 'from_name' => "ISP {$company->code}"],
            ...$over,
        ]);
    }

    /** @return array{from: list<string>, fromName: list<string>, mailer: string} */
    private function lastMail(): array
    {
        $this->assertNotEmpty($this->sent, 'nothing was sent');
        $event = $this->sent[array_key_last($this->sent)];

        return [
            'from' => array_map(fn ($address) => $address->getAddress(), $event->message->getFrom()),
            'fromName' => array_map(fn ($address) => $address->getName(), $event->message->getFrom()),
            'mailer' => $event->data['mailer'],
        ];
    }

    private function send(User $to, Notification $notification): void
    {
        app(ChannelManager::class)->sendNow($to, $notification);
    }

    public function test_the_channel_is_the_app_channel(): void
    {
        $this->assertInstanceOf(CompanyMailChannel::class, app(MailChannel::class));
        $this->assertInstanceOf(CompanyMailChannel::class, app(ChannelManager::class)->driver('mail'));
    }

    public function test_an_enabled_company_sends_from_its_own_address(): void
    {
        $this->mailRow($this->subA);
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame(['from' => ['billing@SBA.test'], 'fromName' => ['ISP SBA'], 'mailer' => 'ondemand'], $this->lastMail());
        $this->assertSame(1, CompanyMailProbe::$toMailCalls);
        $this->assertCount(1, $this->sent);
    }

    public function test_without_a_row_the_platform_mailer_is_used(): void
    {
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame(['from' => ['platform@inbils.test'], 'fromName' => ['Inbils'], 'mailer' => 'array'], $this->lastMail());
    }

    public function test_a_disabled_or_another_companys_row_does_not_count(): void
    {
        $this->mailRow($this->subA, ['is_enabled' => false]);
        $this->mailRow($this->subB);
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame('platform@inbils.test', $this->lastMail()['from'][0], 'a disabled row falls back to the platform sender');
        $this->assertSame('array', $this->lastMail()['mailer'], 'and so does the company of a row that is not its own');
    }

    public function test_a_queued_notification_without_a_scope_is_sent_for_its_recipients_company(): void
    {
        $this->mailRow($this->subA);
        $user = $this->groupUser($this->subA, 'staff');

        $this->send($user, new CompanyMailProbe());

        $this->assertSame('billing@SBA.test', $this->lastMail()['from'][0], 'the worker has no company in scope: the home company of the recipient stands in');
    }

    public function test_the_company_named_by_the_notification_wins_over_the_ambient_one(): void
    {
        $this->mailRow($this->subA);
        $this->mailRow($this->subB);
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe($this->subB->id)));

        $this->assertSame('billing@SBB.test', $this->lastMail()['from'][0]);
    }

    public function test_tenant_admin_invitation_stays_on_the_platform_mailer(): void
    {
        $this->mailRow($this->head);
        $admin = $this->groupUser($this->head);

        CompanyService::runAs($this->head->id, fn () => $this->send($admin, new TenantAdminInvitation($this->head->id)));

        $this->assertSame(['from' => ['platform@inbils.test'], 'fromName' => ['Inbils'], 'mailer' => 'array'], $this->lastMail(), 'a platform invitation never leaves from a tenant\'s own account');
        $this->assertCount(1, $this->sent);
        $this->assertInstanceOf(SentByPlatform::class, new TenantAdminInvitation($this->head->id));
    }

    public function test_a_reset_mail_of_a_user_with_several_companies_leaves_from_the_platform_not_from_the_home_companys_smtp(): void
    {
        $this->mailRow($this->subA);
        $user = $this->groupUser($this->subA, 'staff', $this->subB);

        // A guest asks for the link: no company is in scope, so the recipient's home company would stand in.
        Password::sendResetLink(['email' => $user->email]);

        $this->assertSame(['from' => ['platform@inbils.test'], 'fromName' => ['Inbils'], 'mailer' => 'array'], $this->lastMail(), 'the operator of the home company\'s SMTP server never sees the link');
        $this->assertCount(1, $this->sent);

        // An ordinary mail to the same user still leaves from the company: only a credential is kept off its server.
        $this->send($user, new CompanyMailProbe());

        $this->assertSame('billing@SBA.test', $this->lastMail()['from'][0]);
    }

    public function test_the_credential_mails_of_the_app_and_of_laravel_go_through_the_platform_mailer_whatever_the_company(): void
    {
        $this->mailRow($this->subA);
        $user = $this->groupUser($this->subA, 'staff', $this->subB);
        $notifications = [
            'a reset link' => new ResetPassword('reset-token-0001'),
            'an e-mail verification' => new VerifyEmail(),
            'a customer access link' => new CustomerAccountCreated($this->subA->id),
            'a tenant admin invitation' => new TenantAdminInvitation($this->subA->id),
        ];

        foreach ($notifications as $what => $notification) {
            $this->sent = [];

            CompanyService::runAs($this->subA->id, fn () => app(ChannelManager::class)->sendNow($user, $notification, ['mail']));

            $this->assertSame(['from' => ['platform@inbils.test'], 'fromName' => ['Inbils'], 'mailer' => 'array'], $this->lastMail(), $what);
            $this->assertCount(1, $this->sent, $what);
        }

        $this->assertInstanceOf(SentByPlatform::class, new CustomerAccountCreated($this->subA->id));
    }

    public function test_a_host_the_policy_refuses_behaves_as_no_integration_and_the_row_says_why(): void
    {
        Log::spy();
        $row = $this->mailRow($this->subA, ['settings' => ['transport' => 'array', 'host' => '169.254.169.254', 'port' => 25, 'from_address' => 'billing@SBA.test']]);
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame(['from' => ['platform@inbils.test'], 'fromName' => ['Inbils'], 'mailer' => 'array'], $this->lastMail(), 'sent by the platform, as for a company with no integration');
        $row->refresh();
        $this->assertSame('Host 169.254.169.254 tidak diizinkan (alamat lokal atau metadata).', $row->last_error);
        $this->assertNull($row->verified_at);
        $this->assertSame('failed', CompanyIntegration::stateOf($row), 'the card shows it');
        Log::shouldHaveReceived('warning')->once()->with('integration.host_refused', ['company_id' => $this->subA->id, 'provider' => 'mail']);
    }

    public function test_a_refusal_is_written_to_the_row_once_however_often_it_comes_back(): void
    {
        $row = $this->mailRow($this->subA, ['settings' => ['transport' => 'array', 'host' => '169.254.169.254', 'port' => 25, 'from_address' => 'billing@SBA.test']]);
        $user = $this->groupUser($this->subA, 'staff');
        $send = fn () => CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $send();
        $firstCheck = $row->refresh()->last_checked_at;
        $this->travel(5)->minutes();
        $send();

        $this->assertCount(2, $this->sent, 'both mails were sent, by the platform');
        $this->assertEquals($firstCheck, $row->refresh()->last_checked_at, 'the second refusal left the row as it was');
    }

    public function test_a_company_cannot_point_its_mail_at_a_server_of_the_platform_outside_the_local_environments(): void
    {
        config(['mail.mailers.smtp.host' => '10.9.8.9']);
        $this->app->detectEnvironment(fn (): string => 'production');
        $row = $this->mailRow($this->subA, ['settings' => ['host' => '10.9.8.9', 'port' => 25, 'from_address' => 'billing@SBA.test']]);
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame('platform@inbils.test', $this->lastMail()['from'][0], 'the platform mailer, not a connection to its own mail server in the name of the company');
        $this->assertSame('Host 10.9.8.9 tidak diizinkan (alamat lokal atau metadata).', $row->refresh()->last_error);
    }

    public function test_to_mail_runs_once(): void
    {
        $user = $this->groupUser($this->subA, 'staff');

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));
        $this->assertSame(1, CompanyMailProbe::$toMailCalls, 'on the platform mailer');

        $this->mailRow($this->subA);
        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));
        $this->assertSame(2, CompanyMailProbe::$toMailCalls, 'and on the company\'s own: one more call, not two');
    }

    public function test_a_row_saved_after_a_send_is_used_by_the_next_one(): void
    {
        $user = $this->groupUser($this->subA, 'staff');
        $row = $this->mailRow($this->subA);

        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));
        $this->assertSame('billing@SBA.test', $this->lastMail()['from'][0]);

        $row->update(['settings' => [...$row->settings, 'from_address' => 'new@sba.test']]);
        CompanyService::runAs($this->subA->id, fn () => $this->send($user, new CompanyMailProbe()));

        $this->assertSame('new@sba.test', $this->lastMail()['from'][0], 'no mailer is kept between two sends');
    }

    public function test_the_array_and_log_transports_are_for_local_testing_and_e2e_only(): void
    {
        $row = $this->mailRow($this->subA, ['settings' => ['transport' => 'log', 'host' => '10.0.0.5', 'port' => 2525, 'from_address' => 'a@sba.test']]);

        $this->assertSame('log', (string) CompanyMailChannel::mailerFor($row)->getSymfonyTransport());

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertSame('smtp://10.0.0.5:2525', (string) CompanyMailChannel::mailerFor($row)->getSymfonyTransport(), 'in production the key is ignored');
    }

    /** @return array<string, array{TransportException, string}> */
    public static function failures(): array
    {
        return [
            'a refused login' => [
                new TransportException('Failed to authenticate on SMTP server with username "billing" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication credentials invalid".".', 535),
                'Login SMTP ditolak. Periksa nama pengguna dan kata sandi.',
            ],
            'a refused login without the Symfony wording' => [
                new UnexpectedResponseException('Expected response code "235" but got code "535".', 535),
                'Login SMTP ditolak. Periksa nama pengguna dan kata sandi.',
            ],
            'a failed STARTTLS' => [
                new TransportException('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed with code 1.'),
                'Gagal membuka TLS ke server SMTP. Periksa enkripsi dan port.',
            ],
            'a TLS handshake on port 465' => [
                new TransportException('Connection could not be established with host "ssl://smtp.isp.test:465": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number'),
                'Gagal membuka TLS ke server SMTP. Periksa enkripsi dan port.',
            ],
            'a server that insists on TLS' => [
                new TransportException('TLS required but neither TLS or STARTTLS are in use.'),
                'Gagal membuka TLS ke server SMTP. Periksa enkripsi dan port.',
            ],
            'a refused sender (550 on MAIL FROM)' => [
                new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.7.1 Sender address rejected".', 550),
                'Server SMTP menolak alamat pengirim billing@isp.test.',
            ],
            'a refused sender (553 on MAIL FROM)' => [
                new UnexpectedResponseException('Expected response code "250" but got code "553", with message "553 5.1.8 Domain of sender address does not exist".', 553),
                'Server SMTP menolak alamat pengirim billing@isp.test.',
            ],
            'a refused recipient (550 on RCPT TO)' => [
                new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550),
                'Email uji gagal: server SMTP menjawab kode 550.',
            ],
            'a busy server' => [
                new UnexpectedResponseException('Expected response code "220" but got code "421", with message "421 4.3.2 Service not available".', 421),
                'Email uji gagal: server SMTP menjawab kode 421.',
            ],
            'a closed port' => [
                new TransportException('Connection could not be established with host "mail.isp.test:587": stream_socket_client(): Unable to connect to mail.isp.test:587 (Connection refused)'),
                'Server SMTP tidak dapat dihubungi dalam 5 detik (mail.isp.test:587).',
            ],
            'a timeout' => [
                new TransportException('Connection to "mail.isp.test:587" timed out.'),
                'Server SMTP tidak dapat dihubungi dalam 5 detik (mail.isp.test:587).',
            ],
            'a dropped connection' => [
                new TransportException('Connection to "mail.isp.test:587" has been closed unexpectedly.'),
                'Server SMTP tidak dapat dihubungi dalam 5 detik (mail.isp.test:587).',
            ],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_exchange_is_explained_in_indonesian_without_repeating_the_exception(TransportException $failure, string $message): void
    {
        $row = $this->mailRow($this->head, ['settings' => ['host' => 'mail.isp.test', 'port' => 587, 'from_address' => 'billing@isp.test']]);

        $this->assertSame($message, CompanyMailChannel::failureMessage($failure, $row));
        $this->assertStringNotContainsString($failure->getMessage(), $message, 'what the server said is not repeated');
    }
}

/** A notification that counts how often its mail is built, as CustomerAccountCreated builds (and spends) a reset token there. */
final class CompanyMailProbe extends Notification
{
    public static int $toMailCalls = 0;

    public function __construct(public readonly ?int $companyId = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        self::$toMailCalls++;

        return (new MailMessage())->subject('Probe')->line('Halo.');
    }
}
