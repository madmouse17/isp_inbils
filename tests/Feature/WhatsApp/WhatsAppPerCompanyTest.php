<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\Core\WhatsAppInboundReceipt;
use App\Models\Core\WhatsAppMessage;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\WhatsApp\Fixtures\ProbeNotification;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

/**
 * Every company sends from its own WhatsApp account (number, token, app secret, verify token: its row in
 * company_integrations) while Meta calls one shared webhook. The number a payload names finds the companies that may
 * own it, and only a company whose own app secret signs the body is believed.
 */
class WhatsAppPerCompanyTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    /** Two Meta apps, each with its own number, token, app secret and verify token. */
    private const ALPHA = ['number' => '100000000000001', 'token' => 'wa-token-alpha', 'secret' => 'wa-app-secret-alpha', 'verify' => 'wa-verify-token-alpha'];

    private const BETA = ['number' => '200000000000002', 'token' => 'wa-token-beta', 'secret' => 'wa-app-secret-beta', 'verify' => 'wa-verify-token-beta'];

    private const HANDSHAKE = '/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=424242&hub.verify_token=';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.graph_version' => 'v25.0']);
        $this->fakeWhatsApp();
        // Meta calls with no session, no user and no ambient company.
        CompanyService::useCompanyForTests(null);
    }

    /** @param array{number: string, token: string, secret: string, verify: string} $account */
    private function company(string $name, array $account, bool $enabled = true): Company
    {
        $company = $this->whatsAppEnabledFor(Company::factory()->create(['name' => $name]));
        $this->account($company, $account, $enabled);

        return $company;
    }

    /** @param array{number: string, token: string, secret: string, verify: string} $account */
    private function account(Company $company, array $account, bool $enabled = true): CompanyIntegration
    {
        return $this->whatsAppIntegration($company, $account['number'], [
            'access_token' => $account['token'],
            'app_secret' => $account['secret'],
            'webhook_verify_token' => $account['verify'],
        ], $enabled);
    }

    private function sentMessage(Company $company, string $wamid): WhatsAppMessage
    {
        return WhatsAppMessage::factory()->create(['company_id' => $company->id, 'status' => 'sent', 'provider_message_id' => $wamid]);
    }

    private function fresh(WhatsAppMessage $message): WhatsAppMessage
    {
        return WhatsAppMessage::withoutCompany()->findOrFail($message->id);
    }

    /** @return array<string, string> */
    private function statusEntry(string $wamid, string $status): array
    {
        return ['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '6281234567890'];
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, string $secret): TestResponse
    {
        [$body, $server] = $this->signedWebhook($payload, $secret);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
    }

    /**
     * One body with a change per number, the way Meta batches the numbers of one business account; the delivered status
     * of $wamid rides on the change of Alpha's number.
     *
     * @param  list<string>  $numbers
     * @return array<string, mixed>
     */
    private function bodyForNumbers(array $numbers, string $wamid): array
    {
        $changes = array_map(
            fn (string $number): array => $this->statusWebhook($number === self::ALPHA['number'] ? [$this->statusEntry($wamid, 'delivered')] : [], $number)['entry'][0]['changes'][0],
            $numbers,
        );

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => $changes]]];
    }

    /**
     * The changes of $other added to the body of $payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private function withChangesOf(array $payload, array $other): array
    {
        $payload['entry'][0]['changes'][] = $other['entry'][0]['changes'][0];

        return $payload;
    }

    // --- sending --------------------------------------------------------------------------------------------------

    public function test_each_company_sends_from_its_own_number_and_token(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $beta = $this->company('CV Beta Net', self::BETA);
        $toAlpha = $this->consentedCustomer($alpha, '0812-3456-7890');
        $toBeta = $this->consentedCustomer($beta, '0813-5555-0001');

        Notification::send($toAlpha, new ProbeNotification($alpha->id));
        Notification::send($toBeta, new ProbeNotification($beta->id));

        $requests = $this->graphRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('https://graph.facebook.com/v25.0/'.self::ALPHA['number'].'/messages', $requests[0]->url());
        $this->assertSame(['Bearer '.self::ALPHA['token']], $requests[0]->header('Authorization'));
        $this->assertSame('6281234567890', $requests[0]->data()['to']);
        $this->assertSame('https://graph.facebook.com/v25.0/'.self::BETA['number'].'/messages', $requests[1]->url());
        $this->assertSame(['Bearer '.self::BETA['token']], $requests[1]->header('Authorization'));
        $this->assertSame('6281355550001', $requests[1]->data()['to']);

        $this->assertSame(['sent'], $this->whatsAppRows($alpha->id)->pluck('status')->all());
        $this->assertSame(['sent'], $this->whatsAppRows($beta->id)->pluck('status')->all());
    }

    public function test_a_company_without_a_row_is_skipped_as_disabled(): void
    {
        $this->company('PT Alpha Net', self::ALPHA); // another company has a working account: it is not lent out
        $bare = $this->whatsAppEnabledFor(Company::factory()->create(['name' => 'CV Tanpa Akun']));
        $customer = $this->consentedCustomer($bare);

        Notification::send($customer, new ProbeNotification($bare->id));

        $row = $this->whatsAppRows($bare->id)->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('disabled', $row->skip_reason);
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function closedAccounts(): array
    {
        return [
            'switched off' => [['enabled' => false]],
            'without an access token' => [['credentials' => ['access_token' => '']]],
            'without a phone number id' => [['number' => '']],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('closedAccounts')]
    public function test_a_row_that_is_off_or_incomplete_is_skipped_as_disabled(array $row): void
    {
        $company = $this->whatsAppEnabledFor(Company::factory()->create());
        $this->whatsAppIntegration($company, $row['number'] ?? self::ALPHA['number'], $row['credentials'] ?? [], $row['enabled'] ?? true);
        $customer = $this->consentedCustomer($company);

        Notification::send($customer, new ProbeNotification($company->id));

        $this->assertSame('disabled', $this->whatsAppRows($company->id)->sole()->skip_reason);
        Http::assertNothingSent();
    }

    public function test_the_client_is_configured_for_the_company_in_scope_only(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $bare = Company::factory()->create();
        $client = app(WhatsAppCloudClient::class);

        $this->assertTrue(CompanyService::runAs($alpha->id, fn (): bool => $client->isConfigured()));
        $this->assertFalse(CompanyService::runAs($bare->id, fn (): bool => $client->isConfigured()), 'no row');
        $this->assertFalse($client->isConfigured(), 'no company in scope');
    }

    // --- the shared webhook: statuses -------------------------------------------------------------------------------

    public function test_the_webhook_routes_statuses_by_phone_number_id(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $beta = $this->company('CV Beta Net', self::BETA);
        $gamma = $this->company('PT Gamma Net', self::ALPHA); // the same number and Meta app as Alpha
        $a = $this->sentMessage($alpha, 'wamid.ALPHA');
        $b = $this->sentMessage($beta, 'wamid.BETA');
        $c = $this->sentMessage($gamma, 'wamid.GAMMA');

        // Alpha's number moves the messages of both companies on it, and nothing of Beta's, whatever the body claims.
        $this->deliver($this->statusWebhook([
            $this->statusEntry('wamid.ALPHA', 'delivered'),
            $this->statusEntry('wamid.GAMMA', 'read'),
            $this->statusEntry('wamid.BETA', 'delivered'),
        ], self::ALPHA['number']), self::ALPHA['secret'])->assertOk();

        $this->assertSame('delivered', $this->fresh($a)->status);
        $this->assertSame('read', $this->fresh($c)->status);
        $this->assertSame('sent', $this->fresh($b)->status, 'Beta does not send from Alpha\'s number');

        $this->deliver($this->statusWebhook([
            $this->statusEntry('wamid.BETA', 'delivered'),
            $this->statusEntry('wamid.ALPHA', 'read'),
        ], self::BETA['number']), self::BETA['secret'])->assertOk();

        $this->assertSame('delivered', $this->fresh($b)->status);
        $this->assertSame('delivered', $this->fresh($a)->status, 'Alpha does not send from Beta\'s number');
    }

    // --- the shared webhook: who is believed ------------------------------------------------------------------------

    public function test_a_payload_signed_with_another_company_secret_is_refused(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $this->company('CV Beta Net', self::BETA);
        $message = $this->sentMessage($alpha, 'wamid.ALPHA');
        $payload = $this->statusWebhook([$this->statusEntry('wamid.ALPHA', 'delivered')], self::ALPHA['number']);
        [$body] = $this->signedWebhook($payload, self::ALPHA['secret']);

        $this->deliver($payload, self::BETA['secret'])->assertForbidden()->assertContent(''); // a real secret, but not Alpha's
        $this->deliver($payload, 'a-forged-secret')->assertForbidden();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=nothex'], $body)->assertForbidden();
        $this->assertSame('sent', $this->fresh($message)->status);

        $this->deliver($payload, self::ALPHA['secret'])->assertOk();
        $this->assertSame('delivered', $this->fresh($message)->status);
    }

    public function test_a_number_shared_by_two_companies_believes_only_the_company_whose_secret_signs_the_payload(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $gamma = $this->company('PT Gamma Net', [...self::ALPHA, 'secret' => 'wa-app-secret-of-gamma']);
        $a = $this->sentMessage($alpha, 'wamid.ALPHA');
        $c = $this->sentMessage($gamma, 'wamid.GAMMA');
        $payload = $this->statusWebhook([$this->statusEntry('wamid.ALPHA', 'delivered'), $this->statusEntry('wamid.GAMMA', 'delivered')], self::ALPHA['number']);

        $this->deliver($payload, 'wa-app-secret-of-gamma')->assertOk();

        $this->assertSame('sent', $this->fresh($a)->status, 'Gamma\'s secret does not speak for Alpha');
        $this->assertSame('delivered', $this->fresh($c)->status);
    }

    public function test_a_payload_applies_only_to_the_numbers_its_signature_covers(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $beta = $this->company('CV Beta Net', self::BETA);
        $a = $this->sentMessage($alpha, 'wamid.ALPHA');
        $b = $this->sentMessage($beta, 'wamid.BETA');
        $inAlpha = $this->consentedCustomer($alpha, '0812-3456-7890');
        $inBeta = $this->consentedCustomer($beta, '0812-3456-7890');
        $payload = $this->withChangesOf(
            $this->statusWebhook([$this->statusEntry('wamid.ALPHA', 'delivered')], self::ALPHA['number']),
            $this->statusWebhook([$this->statusEntry('wamid.BETA', 'delivered')], self::BETA['number']),
        );
        $payload = $this->withChangesOf($payload, $this->textWebhook('6281234567890', 'STOP', phoneNumberId: self::BETA['number']));

        $this->deliver($payload, self::ALPHA['secret'])->assertOk(); // one body, two numbers, signed for Alpha's only

        $this->assertSame('delivered', $this->fresh($a)->status);
        $this->assertSame('sent', $this->fresh($b)->status);
        $this->assertNull($inAlpha->fresh()->whatsapp_opt_out_at);
        $this->assertNull($inBeta->fresh()->whatsapp_opt_out_at, 'a STOP to Beta\'s number needs Beta\'s signature');
    }

    public function test_a_payload_for_a_number_nobody_owns_or_a_company_that_is_off_is_refused(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $off = $this->company('CV Mati', self::BETA, enabled: false);
        $message = $this->sentMessage($alpha, 'wamid.ALPHA');
        $offMessage = $this->sentMessage($off, 'wamid.OFF');

        $this->deliver($this->statusWebhook([$this->statusEntry('wamid.ALPHA', 'delivered')], '300000000000003'), self::ALPHA['secret'])->assertForbidden();
        $this->deliver($this->statusWebhook([$this->statusEntry('wamid.OFF', 'delivered')], self::BETA['number']), self::BETA['secret'])->assertForbidden();

        $this->assertSame('sent', $this->fresh($message)->status);
        $this->assertSame('sent', $this->fresh($offMessage)->status);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function payloadsThatNameNoNumber(): array
    {
        $in = fn (array $value): array => ['entry' => [['changes' => [['value' => $value]]]]];

        return [
            'no entries' => [['object' => 'whatsapp_business_account', 'entry' => []]],
            'an unexpected shape' => [['unexpected' => ['shape' => true]]],
            'changes that are not a list' => [['entry' => [['changes' => 'not-a-list']]]],
            'a value without metadata' => [$in(['statuses' => ['junk']])],
            'a number that is not a string' => [$in(['metadata' => ['phone_number_id' => (int) self::ALPHA['number']]])],
            'a number that is a list' => [$in(['metadata' => ['phone_number_id' => [self::ALPHA['number']]]])],
            'an empty number' => [$in(['metadata' => ['phone_number_id' => '']])],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('payloadsThatNameNoNumber')]
    public function test_a_payload_that_names_no_number_matches_no_company_even_when_it_is_signed(array $payload): void
    {
        $this->company('PT Alpha Net', self::ALPHA);

        $this->deliver($payload, self::ALPHA['secret'])->assertForbidden();
    }

    public function test_a_signed_body_that_is_not_json_matches_no_company(): void
    {
        $this->company('PT Alpha Net', self::ALPHA);
        $notJson = 'not json at all';

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $notJson, self::ALPHA['secret'])], $notJson)->assertForbidden();
    }

    public function test_a_payload_that_lists_many_numbers_is_looked_up_for_the_first_twenty_only(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $message = $this->sentMessage($alpha, 'wamid.ALPHA');
        $junk = array_map(fn (int $n): string => '9'.str_pad((string) $n, 14, '0', STR_PAD_LEFT), range(1, 20));

        $this->deliver($this->bodyForNumbers([...array_slice($junk, 0, 19), self::ALPHA['number']], 'wamid.ALPHA'), self::ALPHA['secret'])->assertOk();
        $this->assertSame('delivered', $this->fresh($message)->status, 'the signed number was the twentieth');

        $this->deliver($this->bodyForNumbers([...$junk, self::ALPHA['number']], 'wamid.ALPHA'), self::ALPHA['secret'])->assertForbidden();
    }

    public function test_a_forged_post_that_names_a_number_only_reads_the_integration_table(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $message = $this->sentMessage($alpha, 'wamid.ALPHA');
        $payload = $this->statusWebhook([$this->statusEntry('wamid.ALPHA', 'delivered')], self::ALPHA['number']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->deliver($payload, 'a-forged-secret')->assertForbidden();

        $this->assertNotSame([], $queries, 'the number was looked up');
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select .* from `company_integrations` where /', $sql);
        }
        $this->assertSame('sent', $this->fresh($message)->status);
    }

    public function test_a_row_that_cannot_be_decrypted_is_not_believed_and_the_other_companies_keep_their_webhook(): void
    {
        $broken = $this->company('PT Rusak Net', self::ALPHA);
        $healthy = $this->company('PT Gamma Net', [...self::ALPHA, 'secret' => 'wa-app-secret-of-gamma', 'verify' => 'wa-verify-token-of-gamma']); // on the broken row's number
        $this->company('CV Beta Net', self::BETA);
        $brokenMessage = $this->sentMessage($broken, 'wamid.RUSAK');
        $healthyMessage = $this->sentMessage($healthy, 'wamid.GAMMA');
        // The application key changed after this row was saved: its secrets are sealed with another key and do not open.
        $sealedWithAnotherKey = (new Encrypter(str_repeat('k', 32), (string) config('app.cipher')))
            ->encrypt((string) json_encode(['app_secret' => self::ALPHA['secret'], 'webhook_verify_token' => self::ALPHA['verify']]), false);
        DB::table('company_integrations')->where('company_id', $broken->id)->where('provider', 'whatsapp')->update(['credentials' => $sealedWithAnotherKey]);

        $this->deliver($this->statusWebhook([$this->statusEntry('wamid.RUSAK', 'delivered'), $this->statusEntry('wamid.GAMMA', 'delivered')], self::ALPHA['number']), 'wa-app-secret-of-gamma')->assertOk();

        $this->assertSame('delivered', $this->fresh($healthyMessage)->status);
        $this->assertSame('sent', $this->fresh($brokenMessage)->status, 'a company whose secret cannot be read is not believed');
        $this->deliver($this->statusWebhook([$this->statusEntry('wamid.RUSAK', 'read')], self::ALPHA['number']), self::ALPHA['secret'])->assertForbidden(); // not even with the secret it was sealed with
        $this->get(self::HANDSHAKE.'wa-verify-token-of-gamma')->assertOk()->assertContent('424242');
        $this->get(self::HANDSHAKE.self::BETA['verify'])->assertOk()->assertContent('424242');
        $this->get(self::HANDSHAKE.self::ALPHA['verify'])->assertForbidden();
    }

    // --- the shared webhook: STOP -----------------------------------------------------------------------------------

    public function test_a_stop_reply_opts_out_only_in_the_companies_of_that_number(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $beta = $this->company('CV Beta Net', self::BETA);
        $gamma = $this->company('PT Gamma Net', self::ALPHA); // sends from Alpha's number too
        $inAlpha = $this->consentedCustomer($alpha, '0812-3456-7890');
        $inBeta = $this->consentedCustomer($beta, '+62 812 3456 7890'); // the same person is Beta's customer as well
        $inGamma = $this->consentedCustomer($gamma, '62 812-3456-7890');
        $neighbour = $this->consentedCustomer($alpha, '0812-3456-7899');

        $this->deliver($this->textWebhook('6281234567890', 'STOP', 'wamid.STOPALPHA', phoneNumberId: self::ALPHA['number']), self::ALPHA['secret'])->assertOk();

        $this->assertNotNull($inAlpha->fresh()->whatsapp_opt_out_at);
        $this->assertNotNull($inGamma->fresh()->whatsapp_opt_out_at);
        $this->assertNull($inBeta->fresh()->whatsapp_opt_out_at, 'Beta has another number: the STOP was not said to Beta');
        $this->assertNull($neighbour->fresh()->whatsapp_opt_out_at);
        $audited = Activity::query()->where('log_name', 'whatsapp')->where('description', 'opted_out_by_reply')->pluck('company_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$alpha->id, $gamma->id], $audited);
        $this->assertEqualsCanonicalizing([$alpha->id, $gamma->id], WhatsAppInboundReceipt::withoutCompany()->pluck('company_id')->all());
        $this->assertTrue($inBeta->fresh()->hasWhatsAppConsent());

        // The same words to Beta's number withdraw the consent given to Beta.
        $this->deliver($this->textWebhook('6281234567890', 'STOP', 'wamid.STOPBETA', phoneNumberId: self::BETA['number']), self::BETA['secret'])->assertOk();

        $this->assertNotNull($inBeta->fresh()->whatsapp_opt_out_at);
    }

    // --- the shared webhook: the GET handshake ----------------------------------------------------------------------

    public function test_the_handshake_accepts_any_company_verify_token(): void
    {
        $alpha = $this->company('PT Alpha Net', self::ALPHA);
        $this->company('CV Beta Net', self::BETA);

        foreach ([self::ALPHA, self::BETA] as $account) {
            $this->get(self::HANDSHAKE.$account['verify'])->assertOk()->assertContent('424242');
        }
        $this->get(self::HANDSHAKE.'wa-verify-token-nobody')->assertForbidden()->assertContent('');

        // A company that switched WhatsApp off no longer answers for its token; the others still do.
        $this->account($alpha, self::ALPHA, enabled: false);

        $this->get(self::HANDSHAKE.self::ALPHA['verify'])->assertForbidden();
        $this->get(self::HANDSHAKE.self::BETA['verify'])->assertOk()->assertContent('424242');
    }

    public function test_a_company_without_a_verify_token_does_not_open_the_handshake_to_an_empty_one(): void
    {
        $company = $this->whatsAppEnabledFor(Company::factory()->create());
        $this->whatsAppIntegration($company, self::ALPHA['number'], ['webhook_verify_token' => '']);

        $this->get(self::HANDSHAKE)->assertForbidden();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=424242')->assertForbidden();
    }

    // --- "Uji koneksi" ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: ?int, 1: array<string, mixed>, 2: string}> */
    public static function metaAnswers(): array
    {
        $error = fn (int $code): array => ['error' => ['message' => "(#{$code}) body-sentinel 6281234567890", 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'Atrace']];
        $token = 'Access token ditolak Meta (kedaluwarsa atau dicabut).';
        $number = 'phone_number_id tidak ditemukan atau bukan milik token ini.';
        $permission = 'Token tidak punya izin whatsapp_business_messaging untuk nomor ini.';

        return [
            'an expired token (190)' => [400, $error(190), $token],
            'unauthorised without a body' => [401, [], $token],
            'an unknown number (100)' => [400, $error(100), $number],
            'not found without a body' => [404, [], $number],
            'a missing permission (10)' => [400, $error(10), $permission],
            'the permission range (200)' => [403, $error(200), $permission],
            'the permission range (299)' => [400, $error(299), $permission],
            'forbidden without a body' => [403, [], $permission],
            'Meta down (500)' => [500, $error(1), 'Meta sedang bermasalah (HTTP 500).'],
            'a bad gateway' => [502, [], 'Meta sedang bermasalah (HTTP 502).'],
            'a code nobody mapped' => [400, $error(999999), 'Jawaban Meta tidak terduga (HTTP 400).'],
            'a number that is not the one asked for' => [200, ['id' => '999', 'verified_name' => 'PT Lain'], 'Jawaban Meta tidak terduga (HTTP 200).'],
            'a timeout' => [null, [], 'Meta tidak dapat dihubungi dalam 5 detik.'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('metaAnswers')]
    public function test_the_check_maps_meta_errors_to_indonesian(?int $status, array $body, string $message): void
    {
        $row = $this->account(Company::factory()->create(), self::ALPHA);
        $this->whatsAppReply = $status === null
            ? fn () => throw new ConnectionException('cURL error 28: Operation timed out Bearer '.self::ALPHA['token'])
            : fn () => Http::response($body, $status);

        $result = app(WhatsAppCloudClient::class)->check($row);

        $this->assertSame($message, $result);
        $this->assertLessThanOrEqual(252, mb_strlen($result), 'it must fit last_error');
        foreach ([self::ALPHA['token'], self::ALPHA['secret'], self::ALPHA['verify'], self::ALPHA['number'], '6281234567890', 'body-sentinel', 'Atrace'] as $leak) {
            $this->assertStringNotContainsString($leak, $result);
        }
    }

    public function test_the_check_passes_when_meta_knows_the_number_even_before_the_row_is_switched_on(): void
    {
        $row = $this->account(Company::factory()->create(), self::ALPHA, enabled: false);
        $this->whatsAppReply = fn () => Http::response(['verified_name' => 'PT Alpha Net', 'display_phone_number' => '+62 811-0000-1111', 'id' => self::ALPHA['number']]);

        $this->assertNull(app(WhatsAppCloudClient::class)->check($row), 'no company is in scope: the row is the only input');

        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.com/v25.0/'.self::ALPHA['number'].'?fields=display_phone_number,verified_name'
            && $request->header('Authorization') === ['Bearer '.self::ALPHA['token']]);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function incompleteAccounts(): array
    {
        return [
            'no access token' => [['access_token' => ''], self::ALPHA['number']],
            'no phone number id' => [[], ''],
        ];
    }

    /** @param array<string, string> $credentials */
    #[DataProvider('incompleteAccounts')]
    public function test_the_check_names_a_row_that_is_missing_its_token_or_number_without_calling_meta(array $credentials, string $number): void
    {
        $row = $this->whatsAppIntegration(Company::factory()->create(), $number, $credentials);

        $this->assertSame('ID nomor (phone_number_id) atau Access token belum diisi.', app(WhatsAppCloudClient::class)->check($row));

        Http::assertNothingSent();
    }
}
