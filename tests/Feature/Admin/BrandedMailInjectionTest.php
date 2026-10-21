<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * Words typed by a user reach the mail as Markdown and must stay words. A company admin names the company, and the name is the
 * salutation and the footer of every branded mail the platform sends in its own address: a `[text](url)` in it must never become
 * a link. The notifications escape what they put in a line (MailBrand::literal); the mail views escape the name; Markdown's own
 * secured encoding is the last guard for anything echoed without either.
 */
class BrandedMailInjectionTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const LURE = 'ISP Maju [Verifikasi akun](https://evil.example/login)';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private User $admin;

    private ?MessageSent $sent = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'platform@inbils.test', 'mail.from.name' => 'Inbils']);
        $this->admin = $this->createCompanyUser();
        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $this->sent = $event;
        });
    }

    /** The HTML part of a mail of this company, which is called $companyName now. */
    private function htmlOfMailFrom(string $companyName, string $line = 'Halo.'): string
    {
        Company::query()->whereKey($this->admin->company_id)->update(['name' => $companyName]);
        $this->sent = null;

        app(ChannelManager::class)->sendNow($this->admin, new BrandedMailProbe($line));

        $sent = $this->sent;
        $this->assertInstanceOf(MessageSent::class, $sent, 'nothing was sent');

        return (string) $sent->message->getHtmlBody();
    }

    public function test_a_company_name_cannot_make_a_link_in_the_salutation_or_the_footer(): void
    {
        $html = $this->htmlOfMailFrom(self::LURE);

        $this->assertStringContainsString('ISP Maju', $html, 'the name stays readable');
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('>Verifikasi akun</a>', $html, 'no label that hides its target');
    }

    public function test_a_line_that_its_notification_did_not_escape_still_cannot_make_a_link(): void
    {
        $html = $this->htmlOfMailFrom('PT Contoh Net', '[Bayar sekarang](https://evil.example/pay) <b>x</b>');

        $this->assertStringContainsString('[Bayar sekarang]', $html, 'the words stay');
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('>Bayar sekarang</a>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_a_company_name_cannot_break_out_of_the_logo_text(): void
    {
        Storage::fake('public');
        Company::query()->findOrFail($this->admin->company_id)->addMediaFromString(base64_decode(self::PNG))->usingFileName('logo.png')->toMediaCollection('logo');
        $name = 'Maju" style="display:none" data-x="';

        $html = $this->htmlOfMailFrom($name);

        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML($html);
        $logo = $document->getElementsByTagName('img')->item(0);

        $this->assertNotNull($logo, 'the logo heads the mail');
        $this->assertSame($name, $logo->getAttribute('alt'), 'a quote in the name stays inside the attribute');
        $this->assertFalse($logo->hasAttribute('data-x'), 'and adds no attribute of its own');
        $this->assertStringNotContainsString('display:none', $logo->getAttribute('style'), 'and the style stays the template\'s');
    }

    public function test_a_plain_company_name_reads_as_before(): void
    {
        $html = $this->htmlOfMailFrom('PT Contoh Net');

        $this->assertStringContainsString('PT Contoh Net. Hak cipta dilindungi.', $html);
        $this->assertStringContainsString('Salam,', $html);
    }
}

/** The smallest branded mail: one line, set by the test. */
final class BrandedMailProbe extends Notification
{
    public function __construct(private readonly string $line) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Probe')->line($this->line);
    }
}
