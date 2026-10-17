<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\SetCustomerWhatsAppConsentAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppInboundReceipt;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class WhatsAppWebhookTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->company = Company::factory()->create();
        // Meta calls with no session, no user and no ambient company.
        CompanyService::useCompanyForTests(null);
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $secret = null): TestResponse
    {
        [$body, $server] = $this->signedWebhook($payload, $secret ?? self::WA_APP_SECRET);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
    }

    private function message(array $attributes = []): WhatsAppMessage
    {
        return WhatsAppMessage::factory()->create($attributes + ['company_id' => $this->company->id, 'status' => 'sent', 'provider_message_id' => 'wamid.TEST1']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function statusPayload(string $status, array $extra = [], string $id = 'wamid.TEST1', ?int $timestamp = null): array
    {
        return $this->statusWebhook([['id' => $id, 'status' => $status, 'timestamp' => (string) ($timestamp ?? now()->timestamp), 'recipient_id' => '6281234567890'] + $extra]);
    }

    private function fresh(WhatsAppMessage $message): WhatsAppMessage
    {
        return WhatsAppMessage::withoutCompany()->findOrFail($message->id);
    }

    // --- GET handshake -------------------------------------------------------------------------------------------

    public function test_the_handshake_echoes_the_challenge_when_the_token_matches(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1158201444&hub.verify_token='.self::WA_VERIFY_TOKEN)
            ->assertOk()
            ->assertContent('1158201444')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** @return array<string, array{0: string}> */
    public static function badHandshakes(): array
    {
        return [
            'wrong token' => ['hub.mode=subscribe&hub.challenge=1&hub.verify_token=nope'],
            'no token' => ['hub.mode=subscribe&hub.challenge=1'],
            'token as an array' => ['hub.mode=subscribe&hub.challenge=1&hub.verify_token[]='.self::WA_VERIFY_TOKEN],
            'wrong mode' => ['hub.mode=unsubscribe&hub.challenge=1&hub.verify_token='.self::WA_VERIFY_TOKEN],
            'no mode' => ['hub.challenge=1&hub.verify_token='.self::WA_VERIFY_TOKEN],
            'no challenge' => ['hub.mode=subscribe&hub.verify_token='.self::WA_VERIFY_TOKEN],
        ];
    }

    #[DataProvider('badHandshakes')]
    public function test_a_bad_handshake_is_refused_without_echoing_anything(string $query): void
    {
        $this->get('/webhooks/whatsapp?'.$query)->assertForbidden()->assertContent('');
    }

    public function test_the_handshake_is_not_found_while_the_feature_is_not_configured(): void
    {
        $this->disableWhatsApp();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1&hub.verify_token='.self::WA_VERIFY_TOKEN)->assertNotFound();
    }

    public function test_an_unset_verify_token_never_verifies(): void
    {
        config(['services.whatsapp.webhook_verify_token' => '']);

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1&hub.verify_token=')->assertForbidden();
    }

    // --- POST: signature ---------------------------------------------------------------------------------------

    public function test_a_post_without_or_with_a_wrong_signature_is_forbidden_and_changes_nothing(): void
    {
        $message = $this->message();
        $payload = $this->statusPayload('delivered');
        [$body] = $this->signedWebhook($payload);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden()->assertContent('');
        $this->deliver($payload, 'someone-elses-secret')->assertForbidden()->assertContent('');
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body.' ', self::WA_APP_SECRET)], $body)->assertForbidden();

        $this->assertSame('sent', $this->fresh($message)->status);
    }

    public function test_a_forged_post_does_not_touch_the_database_at_all(): void
    {
        $this->message();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"entry":[]}')->assertForbidden();

        $this->assertSame([], $queries);
    }

    public function test_a_post_is_forbidden_while_the_app_secret_is_unset_even_with_a_matching_empty_key_signature(): void
    {
        config(['services.whatsapp.app_secret' => '']);
        $message = $this->message();

        $this->deliver($this->statusPayload('delivered'), '')->assertForbidden();

        $this->assertSame('sent', $this->fresh($message)->status);
    }

    public function test_a_valid_signature_is_answered_200_even_for_content_that_is_ignored(): void
    {
        $this->deliver(['object' => 'whatsapp_business_account', 'entry' => []])->assertOk();
        $this->deliver(['unexpected' => ['shape' => true]])->assertOk();
        $this->deliver(['entry' => [['changes' => 'not-a-list']]])->assertOk();
        $this->deliver(['entry' => [['changes' => [['value' => ['statuses' => ['junk', ['id' => 5, 'status' => ['x']]]]]]]]])->assertOk();

        // Signed but not JSON: nothing to read, still acknowledged. Signed for another body: refused.
        $notJson = 'not json at all';
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $notJson, self::WA_APP_SECRET)], $notJson)->assertOk();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', '{}', self::WA_APP_SECRET)], $notJson)->assertForbidden();
    }

    public function test_only_get_and_post_are_routed_and_no_session_or_csrf_layer_is_attached(): void
    {
        $this->put('/webhooks/whatsapp')->assertStatus(405);
        $this->patch('/webhooks/whatsapp')->assertStatus(405);
        $this->delete('/webhooks/whatsapp')->assertStatus(405);

        foreach (['webhooks.whatsapp.verify', 'webhooks.whatsapp.receive'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertNotContains('web', $middleware);
            $this->assertContains('throttle:whatsapp-webhook', $middleware);
        }
        $this->assertCount(0, array_intersect(Route::getRoutes()->getByName('webhooks.whatsapp.receive')->gatherMiddleware(), [
            EncryptCookies::class,
            StartSession::class,
            VerifyCsrfToken::class,
        ]));
    }

    // --- throttle ----------------------------------------------------------------------------------------------

    public function test_only_requests_that_fail_the_credential_check_are_throttled(): void
    {
        $message = $this->message();

        foreach (range(1, 30) as $i) {
            $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertForbidden();
        }
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(429);
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1&hub.verify_token=wrong')->assertStatus(429);

        // Meta shares the address with the junk, and is never locked out.
        $this->deliver($this->statusPayload('delivered'))->assertOk();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=77&hub.verify_token='.self::WA_VERIFY_TOKEN)->assertOk()->assertContent('77');
        $this->assertSame('delivered', $this->fresh($message)->status);
    }

    public function test_many_valid_calls_are_never_throttled(): void
    {
        foreach (range(1, 40) as $i) {
            $this->deliver($this->statusPayload('sent', [], 'wamid.NOBODY'))->assertOk();
        }
    }

    // --- POST: statuses ----------------------------------------------------------------------------------------

    public function test_a_message_moves_forward_from_sent_to_delivered_to_read(): void
    {
        $message = $this->message(['delivered_at' => null, 'read_at' => null]);

        $this->deliver($this->statusPayload('delivered', [], 'wamid.TEST1', 1_791_950_400))->assertOk();
        $row = $this->fresh($message);
        $this->assertSame('delivered', $row->status);
        $this->assertTrue($row->delivered_at->equalTo('2026-10-14 04:00:00'));
        $this->assertNull($row->read_at);

        $this->deliver($this->statusPayload('read', [], 'wamid.TEST1', 1_791_950_460))->assertOk();
        $row = $this->fresh($message);
        $this->assertSame('read', $row->status);
        $this->assertTrue($row->read_at->equalTo('2026-10-14 04:01:00'));
        $this->assertTrue($row->delivered_at->equalTo('2026-10-14 04:00:00'));
    }

    public function test_a_message_read_at_once_skips_delivered(): void
    {
        $message = $this->message(['delivered_at' => null]);

        $this->deliver($this->statusPayload('read'))->assertOk();

        $row = $this->fresh($message);
        $this->assertSame('read', $row->status);
        $this->assertNotNull($row->read_at);
        $this->assertNull($row->delivered_at);
    }

    public function test_out_of_order_and_duplicate_callbacks_change_nothing(): void
    {
        $message = $this->message();
        $this->deliver($this->statusPayload('read'))->assertOk();
        $this->travelTo(now()->addMinutes(5));
        $settled = $this->fresh($message)->only(['status', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'error_code', 'billable', 'pricing_category', 'updated_at']);

        $this->deliver($this->statusPayload('delivered', ['pricing' => ['billable' => true, 'category' => 'utility']]))->assertOk();
        $this->deliver($this->statusPayload('read', ['pricing' => ['billable' => true, 'category' => 'utility']]))->assertOk();
        $this->deliver($this->statusPayload('sent'))->assertOk();
        $this->deliver($this->statusPayload('failed', ['errors' => [['code' => 131026]]]))->assertOk();

        $this->assertEquals($settled, $this->fresh($message)->only(array_keys($settled)));
    }

    public function test_a_failed_status_stores_the_error_code_and_is_final(): void
    {
        $message = $this->message();

        $this->deliver($this->statusPayload('failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'to 6281234567890']]]], 'wamid.TEST1', 1_791_950_400))->assertOk();

        $row = $this->fresh($message);
        $this->assertSame('failed', $row->status);
        $this->assertSame('131026', $row->error_code);
        $this->assertTrue($row->failed_at->equalTo('2026-10-14 04:00:00'));

        $this->deliver($this->statusPayload('delivered'))->assertOk();
        $this->assertSame('failed', $this->fresh($message)->status, 'failed never goes forward again');
    }

    public function test_failed_without_error_details_still_marks_the_message(): void
    {
        $message = $this->message();

        $this->deliver($this->statusPayload('failed'))->assertOk();

        $row = $this->fresh($message);
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->error_code);
    }

    public function test_a_queued_message_may_fail_but_a_skipped_one_never_changes(): void
    {
        $queued = $this->message(['status' => 'queued', 'provider_message_id' => 'wamid.Q', 'sent_at' => null]);
        $skipped = WhatsAppMessage::factory()->skipped('no_consent')->create(['company_id' => $this->company->id, 'provider_message_id' => 'wamid.S']);

        $this->deliver($this->statusPayload('failed', ['errors' => [['code' => 131000]]], 'wamid.Q'))->assertOk();
        $this->deliver($this->statusPayload('delivered', [], 'wamid.S'))->assertOk();

        $this->assertSame('failed', $this->fresh($queued)->status);
        $this->assertSame('skipped', $this->fresh($skipped)->status);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?bool, 2: ?string}> */
    public static function pricing(): array
    {
        return [
            'billable utility' => [['billable' => true, 'pricing_model' => 'PMP', 'type' => 'regular', 'category' => 'utility'], true, 'utility'],
            'free within a service window' => [['billable' => false, 'pricing_model' => 'PMP', 'type' => 'free_customer_service', 'category' => 'service'], false, 'service'],
            'billable flag missing, regular type' => [['pricing_model' => 'PMP', 'type' => 'regular', 'category' => 'utility'], true, 'utility'],
            'billable flag missing, free type' => [['pricing_model' => 'PMP', 'type' => 'free_customer_service', 'category' => 'utility'], false, 'utility'],
        ];
    }

    /** @param array<string, mixed> $pricing */
    #[DataProvider('pricing')]
    public function test_the_pricing_block_is_stored(array $pricing, ?bool $billable, ?string $category): void
    {
        $message = $this->message(['billable' => null, 'pricing_category' => null]);

        $this->deliver($this->statusPayload('delivered', ['pricing' => $pricing]))->assertOk();

        $row = $this->fresh($message);
        $this->assertSame($billable, $row->billable);
        $this->assertSame($category, $row->pricing_category);
    }

    public function test_a_status_without_pricing_keeps_what_was_stored(): void
    {
        $message = $this->message(['billable' => true, 'pricing_category' => 'utility']);

        $this->deliver($this->statusPayload('delivered'))->assertOk();

        $row = $this->fresh($message);
        $this->assertTrue($row->billable);
        $this->assertSame('utility', $row->pricing_category);
    }

    public function test_an_unknown_message_id_is_acknowledged_and_changes_nothing(): void
    {
        $message = $this->message();

        $this->deliver($this->statusPayload('read', [], 'wamid.SOMEONE_ELSES'))->assertOk();

        $this->assertSame('sent', $this->fresh($message)->status);
    }

    public function test_a_batch_updates_every_message_in_it_across_companies(): void
    {
        $other = Company::factory()->create();
        $a = $this->message(['provider_message_id' => 'wamid.A']);
        $b = $this->message(['provider_message_id' => 'wamid.B', 'company_id' => $other->id]);

        $this->deliver($this->statusWebhook([
            ['id' => 'wamid.A', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '6281234567890'],
            ['id' => 'wamid.B', 'status' => 'read', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '6281234567891'],
        ]))->assertOk();

        $this->assertSame('delivered', $this->fresh($a)->status);
        $this->assertSame('read', $this->fresh($b)->status);
        $this->assertSame($other->id, $this->fresh($b)->company_id);
    }

    // --- POST: STOP / BERHENTI ---------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function optOutWords(): array
    {
        return [['stop'], ['STOP'], [' Berhenti '], ["\tBERHENTI\n"], ['Stop']];
    }

    #[DataProvider('optOutWords')]
    public function test_stop_and_berhenti_opt_the_customer_out_in_every_company_that_has_the_number(string $word): void
    {
        $other = Company::factory()->create();
        $third = Company::factory()->create();
        $a = $this->consentedCustomer($this->company, '0812-3456-7890');
        $b = $this->consentedCustomer($other, '+62 812 3456 7890');
        $bystander = $this->consentedCustomer($third, '0812-3456-7899');

        $this->deliver($this->textWebhook('6281234567890', $word))->assertOk();

        $this->assertNotNull($a->fresh()->whatsapp_opt_out_at);
        $this->assertNotNull($b->fresh()->whatsapp_opt_out_at);
        $this->assertNull($bystander->fresh()->whatsapp_opt_out_at);
        $this->assertFalse($a->fresh()->hasWhatsAppConsent());

        $audits = Activity::query()->where('log_name', 'whatsapp')->where('description', 'opted_out_by_reply')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertEqualsCanonicalizing([$this->company->id, $other->id], $audits->map(fn ($audit) => (int) $audit->company_id)->all());
        foreach ($audits as $audit) {
            $this->assertEqualsCanonicalizing(['customer_id', 'company_id'], array_keys($audit->properties->toArray()));
        }
        $this->assertNull($audits->first()->causer_id);
        Http::assertNothingSent();
    }

    public function test_other_text_and_other_message_types_change_nothing(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');

        foreach (['stop please', 'STOPP', 'help', 'berhenti kirim', '', 'ya'] as $text) {
            $this->deliver($this->textWebhook('6281234567890', $text))->assertOk();
        }
        $button = $this->textWebhook('6281234567890', 'STOP');
        $button['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'button';
        $this->deliver($button)->assertOk();
        $image = $this->textWebhook('6281234567890', 'STOP');
        $image['entry'][0]['changes'][0]['value']['messages'][0] = ['from' => '6281234567890', 'id' => 'wamid.IMG', 'type' => 'image', 'image' => ['id' => '1']];
        $this->deliver($image)->assertOk();

        $this->assertTrue($customer->fresh()->hasWhatsAppConsent());
        $this->assertSame(0, Activity::query()->where('log_name', 'whatsapp')->count());
    }

    public function test_stop_from_an_unknown_or_foreign_number_changes_nothing(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');

        $this->deliver($this->textWebhook('6281234567891', 'STOP'))->assertOk();
        $this->deliver($this->textWebhook('6591234567', 'STOP'))->assertOk();
        $this->deliver($this->textWebhook('', 'STOP'))->assertOk();

        $this->assertTrue($customer->fresh()->hasWhatsAppConsent());
    }

    public function test_a_repeated_stop_keeps_the_first_opt_out_and_audits_once(): void
    {
        // Consent must predate the STOP; with the real clock this test broke once the calendar passed 2026-10-14.
        $this->travelTo('2026-10-14 03:00:00');
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');

        $this->travelTo('2026-10-14 04:00:00');
        $this->deliver($this->textWebhook('6281234567890', 'STOP'))->assertOk();
        $this->travelTo('2026-10-14 05:00:00');
        $this->deliver($this->textWebhook('6281234567890', 'STOP'))->assertOk();

        $this->assertTrue($customer->fresh()->whatsapp_opt_out_at->equalTo('2026-10-14 04:00:00'));
        $this->assertSame(1, Activity::query()->where('log_name', 'whatsapp')->where('description', 'opted_out_by_reply')->count());
    }

    public function test_stop_also_opts_out_a_customer_who_never_consented_and_skips_deleted_ones(): void
    {
        $never = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '0812-3456-7890']);
        $deleted = $this->consentedCustomer($this->company, '0812-3456-7890');
        $deleted->delete();

        $this->deliver($this->textWebhook('6281234567890', 'STOP'))->assertOk();

        $this->assertNotNull($never->fresh()->whatsapp_opt_out_at);
        $this->assertNull(Customer::withTrashed()->withoutCompany()->find($deleted->id)->whatsapp_opt_out_at);
    }

    // --- STOP: redelivery, ordering and receipts -----------------------------------------------------------------

    private function unix(string $when): int
    {
        return CarbonImmutable::parse($when, 'UTC')->timestamp;
    }

    private function stopAudits(): int
    {
        return Activity::query()->where('log_name', 'whatsapp')->where('description', 'opted_out_by_reply')->count();
    }

    public function test_a_redelivered_stop_does_not_undo_consent_that_staff_recorded_afterwards(): void
    {
        $this->travelTo('2026-10-14 03:00:00');
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $stop = $this->textWebhook('6281234567890', 'STOP', 'wamid.STOP1', $this->unix('2026-10-14 04:00:00'));

        $this->travelTo('2026-10-14 04:00:05');
        $this->deliver($stop)->assertOk();
        $this->assertFalse($customer->fresh()->hasWhatsAppConsent());

        $this->travelTo('2026-10-14 06:00:00');
        SetCustomerWhatsAppConsentAction::execute($customer->fresh(), true, User::factory()->create(['company_id' => $this->company->id]));
        $this->assertTrue($customer->fresh()->hasWhatsAppConsent());

        $this->deliver($stop)->assertOk(); // Meta delivers at least once

        $this->assertTrue($customer->fresh()->hasWhatsAppConsent(), 'the old STOP does not withdraw the new consent');
        $this->assertSame(1, $this->stopAudits());
    }

    public function test_a_reply_that_was_handled_is_not_handled_again_whatever_the_state_of_the_customer(): void
    {
        // No consent record at all, so the timestamp rule has nothing to compare with: only the receipt can tell.
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '0812-3456-7890']);
        $stop = $this->textWebhook('6281234567890', 'STOP', 'wamid.STOP2');

        $this->deliver($stop)->assertOk();
        $customer->forceFill(['whatsapp_opt_out_at' => null])->save();
        $this->deliver($stop)->assertOk();

        $this->assertNull($customer->fresh()->whatsapp_opt_out_at);
        $this->assertSame(1, $this->stopAudits());
    }

    public function test_a_stop_sent_before_the_customer_agreed_again_is_ignored_even_under_a_new_message_id(): void
    {
        $this->travelTo('2026-10-14 04:00:00');
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890'); // consent recorded at 04:00:00

        $this->deliver($this->textWebhook('6281234567890', 'STOP', 'wamid.LATE', $this->unix('2026-10-14 03:59:59')))->assertOk();

        $this->assertTrue($customer->fresh()->hasWhatsAppConsent(), 'a STOP that was sent a second before the consent says nothing about the customer today');
        $this->assertSame(0, $this->stopAudits());
    }

    /** @return array<string, array{0: string}> */
    public static function stopsNotOlderThanTheConsent(): array
    {
        return ['in the same second as the consent' => ['2026-10-14 04:00:00'], 'after the consent' => ['2026-10-14 04:00:01']];
    }

    #[DataProvider('stopsNotOlderThanTheConsent')]
    public function test_a_stop_sent_at_or_after_the_latest_consent_withdraws_it(string $sentAt): void
    {
        $this->travelTo('2026-10-14 04:00:00');
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');

        $this->deliver($this->textWebhook('6281234567890', 'STOP', 'wamid.NOW', $this->unix($sentAt)))->assertOk();

        $this->assertFalse($customer->fresh()->hasWhatsAppConsent());
        $this->assertSame(1, $this->stopAudits());
    }

    public function test_a_stop_without_a_message_id_still_withdraws_consent_and_keeps_no_receipt(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $stop = $this->textWebhook('6281234567890', 'STOP');
        unset($stop['entry'][0]['changes'][0]['value']['messages'][0]['id']);

        $this->deliver($stop)->assertOk();

        $this->assertFalse($customer->fresh()->hasWhatsAppConsent());
        $this->assertSame(0, WhatsAppInboundReceipt::withoutCompany()->count());
    }

    public function test_each_company_keeps_one_receipt_per_message_hashed_without_the_number_or_the_text(): void
    {
        $other = Company::factory()->create();
        $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->consentedCustomer($other, '+62 812 3456 7890');
        $wamid = 'wamid.HBgNNjI4MTIzNDU2Nzg5MBUCABIYFDNBMDAwMDAwMDAwMDAw'; // Meta ids embed the sender's number in base64

        $this->deliver($this->textWebhook('6281234567890', 'STOP', $wamid))->assertOk();

        $hash = hash_hmac('sha256', $wamid, (string) config('app.key'));
        $receipts = WhatsAppInboundReceipt::withoutCompany()->orderBy('company_id')->get();
        $this->assertSame([$this->company->id, $other->id], $receipts->pluck('company_id')->all());
        $this->assertSame([$hash, $hash], $receipts->pluck('message_hash')->all());
        $this->assertSame(['id', 'company_id', 'message_hash', 'created_at', 'updated_at', 'public_id'], DB::getSchemaBuilder()->getColumnListing('whatsapp_inbound_receipts'));

        $stored = (string) json_encode(DB::table('whatsapp_inbound_receipts')->get());
        foreach (['wamid', 'NjI4MTIzNDU2Nzg5MA', '6281234567890', 'STOP', 'stop'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }
    }

    public function test_a_company_without_a_matching_customer_keeps_no_receipt(): void
    {
        Company::factory()->create();
        $this->consentedCustomer($this->company, '0812-3456-7890');

        $this->deliver($this->textWebhook('6281234567890', 'STOP', 'wamid.STOP3'))->assertOk();
        $this->deliver($this->textWebhook('6281234567891', 'STOP', 'wamid.STOP4'))->assertOk(); // nobody has this number

        $this->assertSame([$this->company->id], WhatsAppInboundReceipt::withoutCompany()->pluck('company_id')->all());
    }

    public function test_a_failed_withdrawal_rolls_the_receipt_back_so_that_meta_can_deliver_the_reply_again(): void
    {
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $failing = true;
        Customer::updating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('the customer store is down');
            }
        });
        $stop = $this->textWebhook('6281234567890', 'STOP', 'wamid.STOP5');
        $this->withoutExceptionHandling();

        try {
            $this->deliver($stop);
            $this->fail('the failure must reach Meta as an error so that it delivers the reply again');
        } catch (RuntimeException) {
        }

        $this->assertTrue($customer->fresh()->hasWhatsAppConsent());
        $this->assertSame(0, WhatsAppInboundReceipt::withoutCompany()->count());

        $failing = false;
        $this->deliver($stop)->assertOk();

        $this->assertFalse($customer->fresh()->hasWhatsAppConsent());
        $this->assertSame(1, WhatsAppInboundReceipt::withoutCompany()->count());
    }

    public function test_receipts_are_pruned_after_thirty_days(): void
    {
        $row = fn (string $hash, int $daysAgo): WhatsAppInboundReceipt => WhatsAppInboundReceipt::forceCreate(['company_id' => $this->company->id, 'message_hash' => str_repeat($hash, 64), 'created_at' => now()->subDays($daysAgo)]);
        $row('a', 31);
        $recent = $row('b', 29);

        $this->artisan('model:prune', ['--model' => [WhatsAppInboundReceipt::class]])->assertSuccessful();

        $this->assertSame([$recent->id], WhatsAppInboundReceipt::withoutCompany()->pluck('id')->all());
    }

    public function test_a_message_that_is_delivered_while_still_queued_gets_its_sent_time_and_a_sent_one_keeps_it(): void
    {
        $queued = $this->message(['status' => 'queued', 'sent_at' => null, 'provider_message_id' => 'wamid.Q9']);
        $sent = $this->message(['status' => 'sent', 'sent_at' => '2026-10-14 03:00:00', 'provider_message_id' => 'wamid.S9']);

        $this->deliver($this->statusWebhook([
            ['id' => 'wamid.Q9', 'status' => 'delivered', 'timestamp' => (string) $this->unix('2026-10-14 04:00:00'), 'recipient_id' => '6281234567890'],
            ['id' => 'wamid.S9', 'status' => 'delivered', 'timestamp' => (string) $this->unix('2026-10-14 04:00:00'), 'recipient_id' => '6281234567890'],
        ]))->assertOk();

        $this->assertTrue($this->fresh($queued)->sent_at->equalTo('2026-10-14 04:00:00'), 'the message counts against the cap of the month it was sent in');
        $this->assertTrue($this->fresh($sent)->sent_at->equalTo('2026-10-14 03:00:00'));
    }

    // --- logs -------------------------------------------------------------------------------------------------------

    public function test_logs_never_carry_the_body_or_a_phone_number(): void
    {
        $handler = new TestHandler();
        Log::channel(config('logging.default'))->getLogger()->pushHandler($handler);
        $customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->message();

        $this->deliver($this->statusPayload('failed', ['errors' => [['code' => 131026, 'message' => 'to 6281234567890', 'error_data' => ['details' => '6281234567890']]]]))->assertOk();
        $this->deliver($this->textWebhook('6281234567890', 'STOP'))->assertOk();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"leak":"6281234567890"}')->assertForbidden();

        $this->assertNotNull($customer->fresh()->whatsapp_opt_out_at);
        $logged = json_encode(array_map(fn ($record) => [$record['message'], $record['context']], $handler->getRecords()));
        foreach (['6281234567890', '081234567890', '0812-3456-7890', 'leak', self::WA_APP_SECRET, self::WA_TOKEN, 'STOP'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }
}
