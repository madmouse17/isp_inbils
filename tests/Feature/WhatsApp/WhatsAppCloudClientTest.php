<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppSendRejected;
use App\Services\WhatsApp\WhatsAppTransientError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppCloudClientTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private WhatsAppCloudClient $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->company = Company::factory()->create(); // its row comes from enableWhatsApp()
        // The client sends for the company in scope, the way a queued message does inside runAs().
        CompanyService::useCompanyForTests($this->company->id);
        $this->client = app(WhatsAppCloudClient::class);
    }

    public function test_it_is_configured_only_with_an_enabled_row_that_has_a_token_and_a_phone_number_id(): void
    {
        $this->assertTrue($this->client->isConfigured());

        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, ['access_token' => '']);
        $this->assertFalse($this->client->isConfigured(), 'no access token');

        $this->whatsAppIntegration($this->company, '');
        $this->assertFalse($this->client->isConfigured(), 'no phone number id');

        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, enabled: false);
        $this->assertFalse($this->client->isConfigured(), 'switched off');

        $this->whatsAppIntegration($this->company);
        $this->assertTrue($this->client->isConfigured());
    }

    public function test_send_template_posts_the_documented_request_and_returns_the_wamid(): void
    {
        $this->whatsAppReply = fn () => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '6281234567890', 'wa_id' => '6281234567890']],
            'messages' => [['id' => 'wamid.HBgLabc123', 'message_status' => 'accepted']],
        ]);

        $id = $this->client->sendTemplate('6281234567890', 'tagihan_terbit', 'id', ['PT Contoh Net', 'INV-2026-00012']);

        $this->assertSame('wamid.HBgLabc123', $id);
        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v25.0/'.self::WA_PHONE_NUMBER_ID.'/messages'
                && $request->header('Authorization') === ['Bearer '.self::WA_TOKEN]
                && $request->data() === [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => '6281234567890',
                    'type' => 'template',
                    'template' => [
                        'name' => 'tagihan_terbit',
                        'language' => ['code' => 'id'],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => 'PT Contoh Net'],
                                ['type' => 'text', 'text' => 'INV-2026-00012'],
                            ],
                        ]],
                    ],
                ];
        });
    }

    public function test_a_template_without_parameters_sends_no_components(): void
    {
        $this->client->sendTemplate('6281234567890', 'hello_world', 'id', []);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('components', $request->data()['template']));
    }

    public function test_parameters_are_made_acceptable_to_meta(): void
    {
        $this->client->sendTemplate('6281234567890', 't', 'id', ["Ada\nBudi\tCitra      Dewi", '', "   \n ", ' lurus ']);

        Http::assertSent(function (Request $request): bool {
            $texts = array_column($request->data()['template']['components'][0]['parameters'], 'text');

            return $texts === ['Ada Budi Citra Dewi', '-', '-', 'lurus'];
        });
    }

    public function test_it_refuses_to_call_meta_when_not_configured(): void
    {
        $this->disableWhatsApp();

        $this->expectException(\LogicException::class);
        try {
            $this->client->sendTemplate('6281234567890', 't', 'id', []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_refuses_to_call_meta_without_a_company_in_scope(): void
    {
        CompanyService::useCompanyForTests(null);

        $this->expectException(\LogicException::class);
        try {
            $this->client->sendTemplate('6281234567890', 't', 'id', []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_one_client_sends_for_each_company_in_scope_with_that_companys_own_account(): void
    {
        $otherToken = 'wa-token-of-the-other';
        $other = Company::factory()->create();
        $this->whatsAppIntegration($other, '200000000000002', ['access_token' => $otherToken]);

        $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);
        CompanyService::runAs($other->id, fn () => $this->client->sendTemplate('6281234567890', 't', 'id', ['x']));
        $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);

        $requests = $this->graphRequests();
        $this->assertSame(
            [
                'https://graph.facebook.com/v25.0/'.self::WA_PHONE_NUMBER_ID.'/messages',
                'https://graph.facebook.com/v25.0/200000000000002/messages',
                'https://graph.facebook.com/v25.0/'.self::WA_PHONE_NUMBER_ID.'/messages',
            ],
            $requests->map(fn (Request $request): string => $request->url())->all(),
        );
        $this->assertSame(
            [['Bearer '.self::WA_TOKEN], ['Bearer '.$otherToken], ['Bearer '.self::WA_TOKEN]],
            $requests->map(fn (Request $request): array => $request->header('Authorization'))->all(),
        );
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string}> */
    public static function permanentErrors(): array
    {
        $error = fn (int $code) => ['error' => ['message' => "(#$code) something", 'type' => 'OAuthException', 'code' => $code, 'error_data' => ['messaging_product' => 'whatsapp', 'details' => 'recipient 6281234567890 invalid']]];

        return [
            'not a WhatsApp number' => [400, $error(131026), '131026'],
            'template does not exist' => [404, $error(132001), '132001'],
            'parameter count mismatch' => [400, $error(132000), '132000'],
            'invalid parameter' => [400, $error(100), '100'],
            'unknown code on a 4xx' => [400, $error(999999), '999999'],
            'a 4xx without an error body' => [400, [], 'http_400'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('permanentErrors')]
    public function test_permanent_client_errors_are_rejected_without_retry(int $status, array $body, string $code): void
    {
        $this->whatsAppReplies($status, $body);

        try {
            $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);
            $this->fail('expected a permanent rejection');
        } catch (WhatsAppSendRejected $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertStringNotContainsString('6281234567890', $e->getMessage());
            $this->assertStringNotContainsString(self::WA_TOKEN, $e->getMessage());
        }
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string}> */
    public static function transientErrors(): array
    {
        $error = fn (int $code) => ['error' => ['message' => "(#$code) something", 'code' => $code]];

        return [
            'server error with a Meta code' => [500, $error(1), '1'],
            'bad gateway without a body' => [502, [], 'http_502'],
            'rate limited by http status' => [429, [], 'http_429'],
            'throughput reached' => [400, $error(130429), '130429'],
            'pair rate limit' => [400, $error(131056), '131056'],
            'business account rate limit' => [400, $error(80007), '80007'],
            'unknown error, try again' => [400, $error(131000), '131000'],
            'service unavailable' => [400, $error(131016), '131016'],
            'expired token waits for the owner' => [401, $error(190), '190'],
            'permission removed waits for the owner' => [400, $error(200), '200'],
            'permission range' => [403, $error(299), '299'],
            'unauthorised without a body' => [401, [], 'http_401'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('transientErrors')]
    public function test_transient_errors_are_retried_by_the_queue(int $status, array $body, string $code): void
    {
        $this->whatsAppReplies($status, $body);

        try {
            $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);
            $this->fail('expected a transient error');
        } catch (WhatsAppTransientError $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertStringNotContainsString(self::WA_TOKEN, $e->getMessage());
        }
    }

    public function test_a_timeout_is_a_transient_error_that_leaks_nothing(): void
    {
        $this->whatsAppReply = fn () => throw new ConnectionException('cURL error 28: Operation timed out Bearer '.self::WA_TOKEN);

        try {
            $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);
            $this->fail('expected a transient error');
        } catch (WhatsAppTransientError $e) {
            $this->assertSame('connection', $e->errorCode);
            $this->assertStringNotContainsString(self::WA_TOKEN, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_an_accepted_response_without_a_message_id_is_not_retried_because_it_may_have_been_sent(): void
    {
        $this->whatsAppReplies(200, ['messaging_product' => 'whatsapp']);

        $this->expectException(WhatsAppSendRejected::class);
        $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);
    }

    public function test_the_configured_graph_version_is_used(): void
    {
        config(['services.whatsapp.graph_version' => 'v26.0']);

        $this->client->sendTemplate('6281234567890', 't', 'id', ['x']);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/'));
    }

    /** A body of the shape Meta sends: the number the payload is about is where the companies are found. */
    private function bodyFor(string $phoneNumberId): string
    {
        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $phoneNumberId]]]]]]]);
    }

    public function test_signature_accepts_only_the_hmac_of_the_exact_body_with_the_app_secret_of_a_company_on_the_number(): void
    {
        $body = $this->bodyFor(self::WA_PHONE_NUMBER_ID);
        $valid = 'sha256='.hash_hmac('sha256', $body, self::WA_APP_SECRET);

        $this->assertTrue($this->client->isValidSignature($body, $valid));
        $this->assertSame([self::WA_PHONE_NUMBER_ID => [$this->company->id]], $this->client->verifiedCompanies($body, $valid));
        $this->assertFalse($this->client->isValidSignature($body.' ', $valid), 'a changed body');
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, 'other-secret')), 'another secret');
        $this->assertFalse($this->client->isValidSignature($body, substr($valid, 7)), 'no sha256= prefix');
        $this->assertFalse($this->client->isValidSignature($body, 'sha1='.hash_hmac('sha1', $body, self::WA_APP_SECRET)), 'another algorithm');
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='), 'empty hash');
        $this->assertFalse($this->client->isValidSignature($body, null), 'no header');
        $this->assertSame([], $this->client->verifiedCompanies($body, 'sha256='.hash_hmac('sha256', $body, 'other-secret')));
    }

    public function test_signature_is_never_valid_while_the_app_secret_is_empty(): void
    {
        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, ['app_secret' => '']);
        $body = $this->bodyFor(self::WA_PHONE_NUMBER_ID);

        // Anyone can compute an HMAC keyed with the empty string.
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, '')));
    }

    public function test_signature_is_never_valid_for_a_number_that_no_enabled_company_owns(): void
    {
        $body = $this->bodyFor('999999999999999');

        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, self::WA_APP_SECRET)), 'nobody owns the number');

        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, enabled: false);
        $body = $this->bodyFor(self::WA_PHONE_NUMBER_ID);

        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, self::WA_APP_SECRET)), 'the only company on the number switched WhatsApp off');
    }

    public function test_verify_token_must_match_and_never_matches_when_unset(): void
    {
        $this->assertTrue($this->client->isValidVerifyToken(self::WA_VERIFY_TOKEN));
        $this->assertFalse($this->client->isValidVerifyToken('wrong'));
        $this->assertFalse($this->client->isValidVerifyToken(null));

        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, ['webhook_verify_token' => '']);
        $this->assertFalse($this->client->isValidVerifyToken(''));
        $this->assertFalse($this->client->isValidVerifyToken(self::WA_VERIFY_TOKEN), 'the row no longer holds it');
    }

    public function test_verify_token_of_a_switched_off_company_never_matches(): void
    {
        $this->whatsAppIntegration($this->company, self::WA_PHONE_NUMBER_ID, enabled: false);

        $this->assertFalse($this->client->isValidVerifyToken(self::WA_VERIFY_TOKEN));
    }
}
