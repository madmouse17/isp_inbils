<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class MidtransGatewayTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private const SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    private const FINISH_URL = 'https://inbils.test/portal/payments/return';

    private MidtransGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = app(MidtransGateway::class);
    }

    private function attempt(): PaymentAttempt
    {
        $this->actingAs($this->createCompanyUser());

        return PaymentAttemptFactory::new()->create(['order_id' => 'ORDER-101']);
    }

    /** @return array<string, mixed> */
    private function snapReply(): array
    {
        return ['token' => '66e4fa55-fdac-4ef9-91b5-733b97d1b862', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v3/redirection/66e4fa55-fdac-4ef9-91b5-733b97d1b862'];
    }

    /** @return array<string, array{string}> */
    public static function gatewaysThatAreOff(): array
    {
        return ['no row' => ['none'], 'a row switched off' => ['off'], 'an enabled row without a key' => ['keyless']];
    }

    #[DataProvider('gatewaysThatAreOff')]
    public function test_gateway_is_disabled_without_a_usable_row_and_cannot_send_anything(string $case): void
    {
        $attempt = $this->attempt();
        match ($case) {
            'off' => $this->midtransIntegration($attempt->company, enabled: false),
            'keyless' => $this->midtransIntegration($attempt->company, ''),
            default => null,
        };
        Http::fake();

        $this->assertFalse($this->gateway->isEnabled());

        try {
            $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
            $this->fail('A disabled gateway must not create transactions.');
        } catch (MidtransGatewayException) {
            // expected
        }

        try {
            $this->gateway->transactionStatus('ORDER-101');
            $this->fail('A disabled gateway must not query Midtrans.');
        } catch (MidtransGatewayException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_creating_a_snap_transaction_sends_basic_auth_and_the_invoice_balance(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        Http::fake([self::SNAP_URL => Http::response($this->snapReply(), 201)]);

        $snap = $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);

        $this->assertSame(['token' => '66e4fa55-fdac-4ef9-91b5-733b97d1b862', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v3/redirection/66e4fa55-fdac-4ef9-91b5-733b97d1b862'], $snap);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($attempt): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::SNAP_URL
                && $request->header('Authorization') === ['Basic '.base64_encode(self::FAKE_SERVER_KEY.':')]
                && $body['transaction_details'] === ['order_id' => 'ORDER-101', 'gross_amount' => 100000]
                && $body['item_details'][0]['price'] * $body['item_details'][0]['quantity'] === 100000
                && $body['callbacks']['finish'] === self::FINISH_URL
                && $body['expiry'] === ['unit' => 'hours', 'duration' => 24]
                && $attempt->order_id === 'ORDER-101';
        });
    }

    public function test_an_https_install_sends_the_company_webhook_as_the_notification_url_for_each_order(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        Http::fake([self::SNAP_URL => Http::response($this->snapReply(), 201)]);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);

        Http::assertSent(fn (Request $request): bool => $request->header('X-Override-Notification') === ['https://billing.example.com/webhooks/midtrans/'.$attempt->company->public_id]);
    }

    public function test_a_plain_http_install_sends_no_notification_url(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        // Midtrans would post the notification unencrypted: the dashboard setting applies instead.
        URL::forceRootUrl('http://billing.example.com');
        URL::forceScheme('http');
        Http::fake([self::SNAP_URL => Http::response($this->snapReply(), 201)]);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('X-Override-Notification'));
    }

    public function test_a_redirect_url_on_another_host_is_refused(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        Http::fake([self::SNAP_URL => Http::response(['token' => 'tok', 'redirect_url' => 'https://phish.example/snap/tok'], 201)]);

        $this->expectException(MidtransGatewayException::class);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
    }

    public function test_a_rejected_snap_request_raises_without_leaking_the_key(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        Http::fake([self::SNAP_URL => Http::response(['error_messages' => ['Access denied due to unauthorized transaction, please check client or server key']], 401)]);

        try {
            $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
            $this->fail('A 401 from Snap must raise.');
        } catch (MidtransGatewayException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_a_network_failure_surfaces_as_a_gateway_exception(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $this->expectException(MidtransGatewayException::class);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
    }

    public function test_production_flag_uses_production_hosts_and_ignores_the_base_url_override(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        $this->midtransSettings(['is_production' => true, 'base_url' => 'http://evil.test']);
        Http::fake(['app.midtrans.com/*' => Http::response(['token' => 't', 'redirect_url' => 'https://app.midtrans.com/snap/v4/redirection/t'], 201)]);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.midtrans.com/snap/v1/transactions');
    }

    public function test_a_production_host_refuses_sandbox_keys_unless_the_owner_opted_in(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        $this->app['env'] = 'production';
        Http::fake();

        $this->assertFalse($this->gateway->isEnabled(), 'sandbox keys must not take payments on a production host');
        try {
            $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
            $this->fail('A disabled gateway must not create transactions.');
        } catch (MidtransGatewayException) {
            $this->addToAssertionCount(1);
        }
        Http::assertNothingSent();

        $this->midtransSettings(['allow_sandbox_in_production' => true]);
        $this->assertTrue($this->gateway->isEnabled(), 'the explicit opt-in turns the sandbox pilot on');

        $this->midtransSettings(['allow_sandbox_in_production' => false, 'is_production' => true]);
        $this->assertTrue($this->gateway->isEnabled(), 'production keys on a production host');
    }

    /** @return array<string, array{string, string}> */
    public static function baseUrlEnvironments(): array
    {
        return [
            'local' => ['local', 'http://127.0.0.1:18911/snap/v1/transactions'],
            'testing' => ['testing', 'http://127.0.0.1:18911/snap/v1/transactions'],
            'e2e' => ['e2e', 'http://127.0.0.1:18911/snap/v1/transactions'],
            'staging' => ['staging', 'https://app.sandbox.midtrans.com/snap/v1/transactions'],
            'production' => ['production', 'https://app.sandbox.midtrans.com/snap/v1/transactions'],
        ];
    }

    #[DataProvider('baseUrlEnvironments')]
    public function test_the_base_url_override_is_honoured_only_in_local_testing_and_e2e(string $environment, string $expectedUrl): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        $this->midtransSettings(['base_url' => 'http://127.0.0.1:18911', 'allow_sandbox_in_production' => true]);
        $this->app['env'] = $environment;
        $host = parse_url($expectedUrl, PHP_URL_HOST);
        Http::fake(['*' => Http::response(['token' => 't', 'redirect_url' => "https://{$host}/snap/v4/redirection/t"], 201)]);

        $this->gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);

        Http::assertSent(fn (Request $request): bool => $request->url() === $expectedUrl);
    }

    public function test_base_url_override_points_a_sandbox_gateway_at_a_mock(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->enableMidtrans();
        $this->midtransSettings(['base_url' => 'http://127.0.0.1:18911/']);
        Http::fake(['127.0.0.1:18911/*' => Http::response(['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'], 404)]);

        $this->assertNull($this->gateway->transactionStatus('ORDER-101'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:18911/v2/ORDER-101/status');
    }

    public function test_signature_matches_an_independently_computed_sha512(): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();

        // sha512("ORDER-101" . "200" . "10000.00" . fake key), computed with `sha512sum`, not with the code under test.
        $payload = [
            'order_id' => 'ORDER-101',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'signature_key' => 'e60db103d6fbf500a20830856b1a383ca071b476bb45c8ae8b8a713e341dda08d502e942c4aca2d9c8ab8402384a7e4f9273e26c92c67c3860f2123c72d9fa30',
        ];

        $this->assertTrue($this->gateway->isValidSignature($payload));
    }

    public function test_signature_rejects_any_altered_field(): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();
        $valid = $this->signMidtransPayload(['order_id' => 'ORDER-101', 'status_code' => '200', 'gross_amount' => '10000.00']);

        $this->assertTrue($this->gateway->isValidSignature($valid));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'order_id' => 'ORDER-102']));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'status_code' => '201']));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'gross_amount' => '10000.01']));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'signature_key' => strrev($valid['signature_key'])]));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'signature_key' => substr($valid['signature_key'], 0, 64)]));
    }

    public function test_signature_rejects_missing_or_non_string_fields(): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();
        $valid = $this->signMidtransPayload(['order_id' => 'ORDER-101', 'status_code' => '200', 'gross_amount' => '10000.00']);

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
            $without = $valid;
            unset($without[$field]);
            $this->assertFalse($this->gateway->isValidSignature($without), "{$field} missing");
        }

        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'gross_amount' => 10000.0]));
        $this->assertFalse($this->gateway->isValidSignature([...$valid, 'order_id' => ['ORDER-101']]));
    }

    public function test_signature_is_never_valid_while_the_company_has_no_key(): void
    {
        $company = $this->createCompanyUser()->company;
        $forged = $this->signMidtransPayload(['order_id' => 'ORDER-101', 'status_code' => '200', 'gross_amount' => '10000.00'], '');

        $this->assertFalse($this->gateway->isValidSignature($forged), 'no row');

        $this->midtransIntegration($company, '');

        $this->assertFalse($this->gateway->isValidSignature($forged), 'a row without a key');
    }

    public function test_a_switched_off_row_still_verifies_signatures_with_its_key(): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();
        $valid = $this->signMidtransPayload(['order_id' => 'ORDER-101', 'status_code' => '200', 'gross_amount' => '10000.00']);
        $this->disableMidtrans();

        $this->assertFalse($this->gateway->isEnabled());
        $this->assertTrue($this->gateway->isValidSignature($valid), 'what a notification is signed with does not depend on the switch');
    }

    public function test_status_lookup_returns_the_signed_midtrans_record(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        $record = $this->midtransPayload($attempt);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response($record)]);

        $this->assertSame($record, $this->gateway->transactionStatus('ORDER-101'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.sandbox.midtrans.com/v2/ORDER-101/status'
            && $request->header('Authorization') === ['Basic '.base64_encode(self::FAKE_SERVER_KEY.':')]);
    }

    /** @return array<string, array{int}> */
    public static function notFoundShapes(): array
    {
        return ['HTTP 404' => [404], 'HTTP 200 with a 404 body' => [200]];
    }

    #[DataProvider('notFoundShapes')]
    public function test_status_lookup_treats_both_not_found_shapes_as_no_transaction(int $httpStatus): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();
        $notFound = ['status_code' => '404', 'status_message' => 'The requested resource is not found', 'id' => '788e20d7-8fe7-4068-98a8-9bc4139bcd87'];
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response($notFound, $httpStatus)]);

        $this->assertNull($this->gateway->transactionStatus('ORDER-101'));
    }

    public function test_status_lookup_raises_on_a_server_error(): void
    {
        $this->createCompanyUser();
        $this->enableMidtrans();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_message' => 'oops'], 500)]);

        $this->assertGatewayFailure();
    }

    public function test_status_lookup_raises_on_a_forged_signature(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response([...$this->midtransPayload($attempt), 'signature_key' => str_repeat('0', 128)])]);

        $this->assertGatewayFailure();
    }

    private function assertGatewayFailure(): void
    {
        try {
            $this->gateway->transactionStatus('ORDER-101');
            $this->fail('The lookup should have failed.');
        } catch (MidtransGatewayException $e) {
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_a_status_answer_without_a_signature_is_accepted_because_it_came_from_midtrans_itself(): void
    {
        $attempt = $this->attempt();
        $this->enableMidtrans();
        $unsigned = $this->midtransPayload($attempt);
        unset($unsigned['signature_key']);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response($unsigned)]);

        $this->assertSame($unsigned, $this->gateway->transactionStatus('ORDER-101'));
    }

    public function test_factory_builds_attempts_for_the_invoices_company(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create(['company_id' => $user->company_id, 'status' => 'sent', 'total' => 250000]);

        $attempt = PaymentAttemptFactory::new()->forInvoice($invoice)->create();

        $this->assertSame($invoice->company_id, $attempt->company_id);
        $this->assertSame('250000.00', $attempt->amount);
        $this->assertSame(250000, $attempt->grossAmount());
        $this->assertMatchesRegularExpression('/^INB-[0-9A-Z]{26}$/', $attempt->order_id);
    }
}
