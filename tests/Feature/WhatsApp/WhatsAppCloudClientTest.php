<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppSendRejected;
use App\Services\WhatsApp\WhatsAppTransientError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppCloudClientTest extends TestCase
{
    use InteractsWithWhatsApp;

    private WhatsAppCloudClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->client = app(WhatsAppCloudClient::class);
    }

    public function test_it_is_configured_only_with_a_token_and_a_phone_number_id(): void
    {
        $this->assertTrue($this->client->isConfigured());

        config(['services.whatsapp.access_token' => '']);
        $this->assertFalse($this->client->isConfigured());

        $this->enableWhatsApp();
        config(['services.whatsapp.phone_number_id' => null]);
        $this->assertFalse($this->client->isConfigured());
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

    public function test_signature_accepts_only_the_hmac_of_the_exact_body_with_the_app_secret(): void
    {
        $body = '{"object":"whatsapp_business_account","entry":[]}';
        $valid = 'sha256='.hash_hmac('sha256', $body, self::WA_APP_SECRET);

        $this->assertTrue($this->client->isValidSignature($body, $valid));
        $this->assertFalse($this->client->isValidSignature($body.' ', $valid), 'a changed body');
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, 'other-secret')), 'another secret');
        $this->assertFalse($this->client->isValidSignature($body, substr($valid, 7)), 'no sha256= prefix');
        $this->assertFalse($this->client->isValidSignature($body, 'sha1='.hash_hmac('sha1', $body, self::WA_APP_SECRET)), 'another algorithm');
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='), 'empty hash');
        $this->assertFalse($this->client->isValidSignature($body, null), 'no header');
    }

    public function test_signature_is_never_valid_while_the_app_secret_is_empty(): void
    {
        config(['services.whatsapp.app_secret' => '']);
        $body = '{}';

        // Anyone can compute an HMAC keyed with the empty string.
        $this->assertFalse($this->client->isValidSignature($body, 'sha256='.hash_hmac('sha256', $body, '')));
    }

    public function test_verify_token_must_match_and_never_matches_when_unset(): void
    {
        $this->assertTrue($this->client->isValidVerifyToken(self::WA_VERIFY_TOKEN));
        $this->assertFalse($this->client->isValidVerifyToken('wrong'));
        $this->assertFalse($this->client->isValidVerifyToken(null));

        config(['services.whatsapp.webhook_verify_token' => '']);
        $this->assertFalse($this->client->isValidVerifyToken(''));
    }
}
