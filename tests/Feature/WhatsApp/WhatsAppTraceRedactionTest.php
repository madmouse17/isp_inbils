<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppTransientError;
use App\Services\WhatsApp\WhatsAppWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Tests\Feature\WhatsApp\Fixtures\ProbeNotification;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;
use Throwable;

/**
 * A stack trace is what failed_jobs.exception and the log files are made of, and PHP keeps the arguments of every
 * frame in it (the production image has no php.ini). A phone number, a message parameter or a token handed to a
 * function would land in both stores. Those parameters are marked #[\SensitiveParameter], so PHP records an
 * opaque object instead, and the image ships zend.exception_ignore_args=On as the second line of defence.
 */
class WhatsAppTraceRedactionTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    /** Every way the recipient's number can be written down. */
    private const NUMBERS = ['6281234567890', '081234567890', '0812-3456-7890', '+6281234567890'];

    /** The template parameters of the probe notification: names and amounts of the customer. */
    private const PARAMETERS = ['PT Contoh Net', 'INV-2026-00012', 'Rp 250.000', 'Rp 0', '14 Okt 2026'];

    /** @var array{0: string|false, 1: string|false}|null */
    private ?array $phpTraceSettings = null;

    protected function tearDown(): void
    {
        if ($this->phpTraceSettings !== null) {
            ini_set('zend.exception_ignore_args', (string) $this->phpTraceSettings[0]);
            ini_set('zend.exception_string_param_max_len', (string) $this->phpTraceSettings[1]);
        }

        parent::tearDown();
    }

    /** PHP without a php.ini, as in the production image: arguments and short strings stay in stack traces. */
    private function keepArgumentsInStackTraces(): void
    {
        $this->phpTraceSettings = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
    }

    /** @return array<string, array{0: string}> */
    public static function failures(): array
    {
        return [
            'Meta has a transient error' => ['transient'],
            'the call times out' => ['timeout'],
            'the access token expired' => ['token'],
            'Meta throttles the sender' => ['throttle'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_send_leaves_no_number_parameter_or_token_in_its_stack_trace(string $failure): void
    {
        $this->keepArgumentsInStackTraces();
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $company = $this->whatsAppEnabledFor(Company::factory()->create(['name' => 'PT Contoh Net']));
        $customer = $this->consentedCustomer($company, '0812-3456-7890');
        $this->whatsAppReply = match ($failure) {
            'transient' => fn (Request $request) => Http::response(['error' => ['code' => 2, 'message' => 'to '.$request->data()['to']]], 500),
            'timeout' => fn (Request $request) => throw new ConnectionException('cURL error 28 sending to '.$request->data()['to'].' with '.$request->header('Authorization')[0]),
            'token' => fn (Request $request) => Http::response(['error' => ['code' => 190, 'message' => 'token '.$request->header('Authorization')[0]]], 401),
            'throttle' => fn () => Http::response(['error' => ['code' => 130429]], 400),
        };

        try {
            Notification::send($customer, new ProbeNotification($company->id));
            $this->fail('the failure must reach the queue so that it is retried');
        } catch (WhatsAppTransientError $e) {
            $this->assertHoldsNoPersonalData($e, [...self::NUMBERS, ...self::PARAMETERS, self::WA_TOKEN, self::WA_APP_SECRET, self::WA_VERIFY_TOKEN]);
            $this->assertRedacted($e, [[WhatsAppCloudClient::class, 'sendTemplate', [0, 3]], [WhatsAppChannel::class, 'transmit', [1, 2]], [WhatsAppChannel::class, 'deliver', [2]]]);
        }
    }

    public function test_a_failed_stop_withdrawal_leaves_no_number_or_message_text_in_its_stack_trace(): void
    {
        $this->keepArgumentsInStackTraces();
        $company = Company::factory()->create();
        $this->consentedCustomer($company, '0812-3456-7890');
        Customer::updating(fn () => throw new RuntimeException('the customer store is down'));
        CompanyService::useCompanyForTests(null);

        try {
            app(WhatsAppWebhookHandler::class)->handle($this->textWebhook('6281234567890', ' Berhenti '));
            $this->fail('the failure must reach the webhook so that Meta delivers the reply again');
        } catch (RuntimeException $e) {
            $this->assertHoldsNoPersonalData($e, [...self::NUMBERS, 'Berhenti', 'Someone']);
            $this->assertRedacted($e, [[WhatsAppWebhookHandler::class, 'optOutCustomers', [0]], [WhatsAppWebhookHandler::class, 'applyReply', [0]], [WhatsAppWebhookHandler::class, 'handle', [0]]]);
        }
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertHoldsNoPersonalData(Throwable $e, array $needles): void
    {
        $stores = [
            'failed_jobs.exception (the exception as a string)' => (string) $e,
            'the trace as a log line prints it' => $e->getTraceAsString(),
            'the message' => $e->getMessage(),
            'every string argument of every frame' => implode("\n", $this->stringsIn(array_column($e->getTrace(), 'args'))),
        ];

        foreach ($stores as $store => $content) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $content, "$store holds `$needle`");
            }
        }
    }

    /**
     * The sensitive arguments are there as opaque objects (so the test cannot pass just because the frames lost their
     * arguments): function => the positions of its arguments that must be redacted.
     *
     * @param  list<array{0: class-string, 1: string, 2: list<int>}>  $frames  class, function, positions
     */
    private function assertRedacted(Throwable $e, array $frames): void
    {
        $this->assertStringContainsString('Object(SensitiveParameterValue)', $e->getTraceAsString());

        foreach ($frames as [$class, $function, $positions]) {
            $frame = collect($e->getTrace())->first(fn (array $frame): bool => ($frame['class'] ?? null) === $class && $frame['function'] === $function);
            $this->assertNotNull($frame, "$function is on the stack");

            foreach ($positions as $position) {
                $this->assertInstanceOf(SensitiveParameterValue::class, $frame['args'][$position], "argument $position of $function");
            }
        }
    }

    /** @return list<string> */
    private function stringsIn(mixed $value): array
    {
        return match (true) {
            is_string($value) => [$value],
            // Objects (models, the redaction marker) are opaque here, as they are in a string trace.
            is_array($value) => array_merge([], ...array_values(array_map(fn (mixed $item): array => $this->stringsIn($item), $value))),
            default => [],
        };
    }

    /** @return array<string, array{0: class-string, 1: string, 2: list<string>}> */
    public static function sensitiveParameters(): array
    {
        return [
            'the Cloud API send' => [WhatsAppCloudClient::class, 'sendTemplate', ['to', 'parameters']],
            'the webhook signature check' => [WhatsAppCloudClient::class, 'isValidSignature', ['rawBody', 'header']],
            'the webhook verify token' => [WhatsAppCloudClient::class, 'isValidVerifyToken', ['token']],
            'the channel gate' => [WhatsAppChannel::class, 'deliver', ['payload']],
            'the channel send' => [WhatsAppChannel::class, 'transmit', ['phone', 'payload']],
            'the webhook entry point' => [WhatsAppWebhookHandler::class, 'handle', ['payload']],
            'a delivery status' => [WhatsAppWebhookHandler::class, 'applyStatus', ['status']],
            'an inbound reply' => [WhatsAppWebhookHandler::class, 'applyReply', ['message']],
            'the STOP withdrawal' => [WhatsAppWebhookHandler::class, 'optOutCustomers', ['phone']],
            'the phone normaliser' => [PhoneNumber::class, 'normalizeIndonesianMobile', ['phone']],
        ];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $parameters
     */
    #[DataProvider('sensitiveParameters')]
    public function test_every_parameter_that_receives_a_number_a_message_or_a_secret_is_marked_sensitive(string $class, string $method, array $parameters): void
    {
        $declared = collect((new ReflectionMethod($class, $method))->getParameters())->keyBy(fn ($parameter): string => $parameter->getName());

        foreach ($parameters as $name) {
            $this->assertTrue($declared->has($name), "$class::$method has no \$$name");
            $this->assertCount(1, $declared[$name]->getAttributes(SensitiveParameter::class), "$class::$method(\$$name) must be #[\\SensitiveParameter]");
        }
    }

    public function test_the_production_image_keeps_no_call_arguments_in_stack_traces(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\s*zend\.exception_ignore_args\s*=\s*(On|1|true)\s*$/mi',
            (string) file_get_contents(base_path('docker/php/conf.d/inbils.ini')),
        );

        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $copy = strpos($dockerfile, 'COPY docker/php/conf.d/inbils.ini /usr/local/etc/php/conf.d/inbils.ini');

        $this->assertNotFalse($copy, 'the drop-in is copied into the directory PHP scans for extra ini files');
        $this->assertGreaterThan(strpos($dockerfile, 'AS application'), $copy, 'the drop-in ships in the application stage, the one that is deployed');
    }
}
