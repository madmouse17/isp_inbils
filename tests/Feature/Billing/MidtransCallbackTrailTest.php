<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\PaymentAttemptService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

/**
 * F5-D15, F5-U16, F5-D21: every callback leaves one log line (the refused ones too, so a wrong or rotated server key
 * shows), a callback that proved who sent it and names a known attempt leaves one audit row on that attempt, and the
 * invoice page lists the last five of them. A signed callback can be replayed, so it is throttled per order.
 */
class MidtransCallbackTrailTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->enableMidtrans();
        $this->fakeMidtrans();
    }

    private function invoice(?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 100000,
        ]);
    }

    private function attempt(?Invoice $invoice = null): PaymentAttempt
    {
        return PaymentAttemptFactory::new()->forInvoice($invoice ?? $this->invoice())->create();
    }

    /**
     * Deliver a notification the way Midtrans does: no session, no user, no ambient company. The Status API answers
     * with $remote, by default the same record.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $remote
     */
    private function notify(PaymentAttempt $attempt, array $overrides = [], ?array $remote = null): TestResponse
    {
        $notification = $this->midtransPayload($attempt, $overrides);
        $this->midtransStatusReturns($remote ?? $notification);
        CompanyService::useCompanyForTests(null);

        return $this->postJson('/webhooks/midtrans', $notification);
    }

    /** @return Collection<int, Activity> */
    private function trailOf(PaymentAttempt $attempt): Collection
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', 'callback_received')->where('subject_id', $attempt->id)->orderBy('id')->get();
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', $event)->count();
    }

    /**
     * What the log line of a callback holds: the four statuses of the notification and the answer, nothing else.
     *
     * @param  array<string, string|null>  $seen
     * @return array<string, string|int|null>
     */
    private function line(array $seen, int $http): array
    {
        return ['order_id' => null, 'transaction_status' => null, 'status_code' => null, 'fraud_status' => null, ...$seen, 'http' => $http];
    }

    public function test_every_callback_leaves_one_log_line_with_the_answer_it_got(): void
    {
        $attempt = $this->attempt();
        $seen = ['order_id' => $attempt->order_id, 'transaction_status' => 'settlement', 'status_code' => '200', 'fraud_status' => 'accept'];
        Log::spy();

        // A genuine settlement and its replay, a forged signature, an order nobody here made, a body that lacks a field,
        // a body that is not JSON, and an order id far longer than any real one.
        $this->notify($attempt)->assertOk();
        $this->notify($attempt)->assertOk();
        $this->postJson('/webhooks/midtrans', [...$this->midtransPayload($attempt), 'signature_key' => str_repeat('a', 128)])->assertForbidden();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt, ['order_id' => 'NOT-OURS-0001']))->assertOk();
        $missing = $this->midtransPayload($attempt);
        unset($missing['transaction_status']);
        $this->postJson('/webhooks/midtrans', $missing)->assertUnprocessable();
        $this->post('/webhooks/midtrans', $this->midtransPayload($attempt))->assertForbidden();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt, ['order_id' => str_repeat('x', 100)]))->assertOk();

        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line($seen, 200))->twice();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line($seen, 403))->once();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line([...$seen, 'order_id' => 'NOT-OURS-0001'], 200))->once();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line(['order_id' => $attempt->order_id, 'status_code' => '200', 'fraud_status' => 'accept'], 422))->once();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line([], 403))->once();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line([...$seen, 'order_id' => str_repeat('x', 64)], 200))->once();
    }

    public function test_a_known_attempt_gets_one_audit_row_per_callback_with_four_statuses_and_the_answer(): void
    {
        $attempt = $this->attempt();

        $this->notify($attempt)->assertOk();

        $row = $this->trailOf($attempt)->sole();
        $this->assertSame($attempt->company_id, (int) $row->company_id);
        $this->assertSame(PaymentAttempt::class, $row->subject_type);
        $this->assertNull($row->causer_id, 'Midtrans is no user');
        $this->assertSame('callback_received', $row->event);
        $this->assertSame('settlement', $row->getExtraProperty('transaction_status'));
        $this->assertSame('200', $row->getExtraProperty('status_code'));
        $this->assertSame('accept', $row->getExtraProperty('fraud_status'));
        $this->assertSame(200, $row->getExtraProperty('http'));
    }

    public function test_replays_add_a_row_each_and_change_nothing_else(): void
    {
        $attempt = $this->attempt();
        $state = fn (): array => PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->only(['status', 'status_reason', 'payment_id', 'settled_at', 'updated_at']);

        $this->notify($attempt)->assertOk();
        $settled = $state();
        $this->travel(2)->minutes();
        $this->notify($attempt)->assertOk();
        $this->notify($attempt)->assertOk();

        $this->assertCount(3, $this->trailOf($attempt), 'one row per delivery, because the invoice page lists deliveries');
        $this->assertSame(1, Payment::withoutCompany()->count());
        $this->assertSame(1, $this->auditCount('settled'));
        $this->assertSame(0, $this->auditCount('exception'));
        $this->assertEquals($settled, $state(), 'a replay writes nothing to the attempt');
    }

    public function test_a_callback_midtrans_could_not_confirm_is_traced_with_its_503_and_the_retry_with_its_200(): void
    {
        $attempt = $this->attempt();
        $notification = $this->midtransPayload($attempt);
        CompanyService::useCompanyForTests(null);
        Log::spy();

        // The Status API is down: Midtrans is told to retry, and the trail says it was.
        $this->midtransStatusReply = fn () => Http::response(['message' => 'down'], 500);
        $this->postJson('/webhooks/midtrans', $notification)->assertStatus(503);
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
        $this->assertSame(0, Payment::withoutCompany()->count());

        $this->midtransStatusReturns($notification);
        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame([503, 200], $this->trailOf($attempt)->map(fn (Activity $row): mixed => $row->getExtraProperty('http'))->all());
        $seen = ['order_id' => $attempt->order_id, 'transaction_status' => 'settlement', 'status_code' => '200', 'fraud_status' => 'accept'];
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line($seen, 503))->once();
        Log::shouldHaveReceived('info')->with('midtrans.callback', $this->line($seen, 200))->once();
        $this->assertSame(1, Payment::withoutCompany()->count(), 'the retry books the payment once');
    }

    public function test_refused_and_foreign_callbacks_leave_no_audit_row_on_any_attempt(): void
    {
        $attempt = $this->attempt();
        $other = $this->attempt($this->invoice(Company::factory()->create(['is_active' => true])));
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', [...$this->midtransPayload($attempt), 'signature_key' => str_repeat('a', 128)])->assertForbidden();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt, ['order_id' => 'NOT-OURS-0001']))->assertOk();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($other, key: 'a-key-that-is-not-this-companys'))->assertForbidden();
        $incomplete = $this->midtransPayload($attempt);
        unset($incomplete['gross_amount']);
        $this->postJson('/webhooks/midtrans', $incomplete)->assertUnprocessable();

        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
    }

    public function test_nothing_secret_reaches_the_log_or_the_audit_rows(): void
    {
        $attempt = $this->attempt();
        $payload = $this->midtransPayload($attempt, ['masked_card' => '48111111-1114', 'va_numbers' => [['bank' => 'bca', 'va_number' => '81234567890']]]);
        $this->midtransStatusReturns($payload);
        CompanyService::useCompanyForTests(null);
        Log::spy();

        $this->postJson('/webhooks/midtrans', $payload)->assertOk();

        $rows = Activity::query()->where('log_name', 'payment_attempt')->get();
        $this->assertNotEmpty($rows);
        $everything = $rows->toJson();
        foreach ([$payload['signature_key'], self::FAKE_SERVER_KEY, $attempt->snap_token, $attempt->redirect_url, '48111111', '81234567890', 'merchant_id', 'gross_amount', 'signature_key'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $everything, 'the trail keeps statuses, never the body of the notification');
        }
        $this->assertEqualsCanonicalizing(
            ['order_id', 'invoice_id', 'amount', 'transaction_status', 'status_code', 'fraud_status', 'http'],
            array_keys($this->trailOf($attempt)->sole()->properties->all()),
        );
        // The log context has exactly the five fields; the signature and the key are not among them.
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'midtrans.callback'
            && array_keys($context) === ['order_id', 'transaction_status', 'status_code', 'fraud_status', 'http'])->once();
    }

    public function test_the_invoice_page_lists_the_last_five_callbacks_of_each_attempt_newest_first(): void
    {
        $invoice = $this->invoice();
        $busy = $this->attempt($invoice);
        $quiet = $this->attempt($invoice);
        $closed = $this->attempt($invoice);
        $service = app(PaymentAttemptService::class);
        // A flood on one attempt costs five rows and cannot push another attempt's trail out of the list.
        foreach (range(1, 30) as $n) {
            $service->recordCallback($busy, ['order_id' => $busy->order_id, 'transaction_status' => 'pending', 'status_code' => '201'], $n === 30 ? 503 : 200);
        }
        $service->recordCallback($quiet, ['order_id' => $quiet->order_id, 'transaction_status' => 'expire', 'status_code' => '407', 'fraud_status' => 'accept'], 200);
        // Rows that are not this page's trail: another event on an attempt of the invoice, and a callback of another invoice's attempt.
        $service->markExpired($closed);
        $service->recordCallback($this->attempt($this->invoice()), ['order_id' => 'X', 'transaction_status' => 'settlement', 'status_code' => '200'], 200);

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('onlinePayments.attempts.data', 3)
                ->where('onlinePayments.attempts.data.0.id', $closed->public_id)
                ->has('onlinePayments.attempts.data.0.callbacks', 0)
                ->where('onlinePayments.attempts.data.1.id', $quiet->public_id)
                ->has('onlinePayments.attempts.data.1.callbacks', 1)
                ->where('onlinePayments.attempts.data.1.callbacks.0.transaction_status', 'expire')
                ->where('onlinePayments.attempts.data.1.callbacks.0.status_code', '407')
                ->where('onlinePayments.attempts.data.1.callbacks.0.fraud_status', 'accept')
                ->where('onlinePayments.attempts.data.1.callbacks.0.http', 200)
                ->where('onlinePayments.attempts.data.2.id', $busy->public_id)
                ->has('onlinePayments.attempts.data.2.callbacks', 5)
                ->where('onlinePayments.attempts.data.2.callbacks.0.http', 503)
                ->where('onlinePayments.attempts.data.2.callbacks.0.transaction_status', 'pending')
                ->where('onlinePayments.attempts.data.2.callbacks.4.http', 200)
                ->where('onlinePayments.attempts.data.2.callbacks.0.at', fn (string $at): bool => $at !== ''));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'row_number()'))->count(), 'the trails of the whole page come in one query');
        $this->assertLessThanOrEqual(2, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from `payments`'))->count(), 'the payments of the attempts are eager loaded, not asked per attempt');
    }

    public function test_the_page_never_shows_another_companys_callbacks(): void
    {
        $invoice = $this->invoice();
        $mine = $this->attempt($invoice);
        $foreign = $this->attempt($this->invoice(Company::factory()->create(['is_active' => true])));
        app(PaymentAttemptService::class)->recordCallback($foreign, ['order_id' => $foreign->order_id, 'transaction_status' => 'deny', 'status_code' => '202'], 200);

        $response = $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('onlinePayments.attempts.data', 1)->has('onlinePayments.attempts.data.0.callbacks', 0));

        $props = $response->viewData('page')['props']['onlinePayments'];
        $this->assertSame($mine->public_id, $props['attempts']['data'][0]['id']);
        $this->assertStringNotContainsString('deny', json_encode($props));
    }

    public function test_an_invoice_that_never_had_an_online_attempt_asks_for_no_trail(): void
    {
        $invoice = $this->invoice();

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('onlinePayments.attempts.data', 0));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(0, $queries->filter(fn (string $sql): bool => str_contains($sql, 'row_number()')));
    }

    public function test_a_signed_callback_replayed_past_ten_a_minute_is_throttled_per_order_and_status_code(): void
    {
        $attempt = $this->attempt();
        $neighbour = $this->attempt();

        foreach (range(1, 10) as $ignored) {
            $this->notify($attempt)->assertOk();
        }
        $this->notify($attempt)->assertStatus(429);

        // A body field outside the signature does not open a new allowance, however the sender rewrites it.
        $this->notify($attempt, ['transaction_status' => 'capture'])->assertStatus(429);
        $this->notify($attempt, ['transaction_status' => 'something-else'])->assertStatus(429);
        // Another order, and another status code of the same order, have their own.
        $this->notify($neighbour)->assertOk();
        $this->notify($attempt, ['status_code' => '201', 'transaction_status' => 'pending'])->assertOk();

        // The allowance is a minute long.
        $this->travel(61)->seconds();
        $this->notify($attempt)->assertOk();
    }

    public function test_junk_that_borrows_an_orders_name_cannot_use_up_its_allowance(): void
    {
        $attempt = $this->attempt();
        $junk = [...$this->midtransPayload($attempt), 'signature_key' => 'bad'];

        // 30 a minute per address; the 31st is refused before it reaches anything.
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/webhooks/midtrans', $junk)->assertForbidden();
        }
        $this->postJson('/webhooks/midtrans', $junk)->assertStatus(429);

        // The same order and status code, signed, still has all of its own allowance.
        foreach (range(1, 10) as $ignored) {
            $this->notify($attempt)->assertOk();
        }
        $this->notify($attempt)->assertStatus(429);
    }

    public function test_the_signature_check_is_unchanged_a_changed_amount_status_code_or_signature_is_refused(): void
    {
        $attempt = $this->attempt();
        $signed = $this->midtransPayload($attempt);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', [...$signed, 'gross_amount' => '1000.00'])->assertForbidden();
        $this->postJson('/webhooks/midtrans', [...$signed, 'status_code' => '201'])->assertForbidden();
        $this->postJson('/webhooks/midtrans', [...$signed, 'signature_key' => strtoupper($signed['signature_key'])])->assertForbidden();

        $this->assertSame(0, Payment::withoutCompany()->count());
    }
}
