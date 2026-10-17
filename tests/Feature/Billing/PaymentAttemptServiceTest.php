<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentAttemptService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class PaymentAttemptServiceTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private const FINISH_URL = 'https://inbils.test/portal/payments/return';

    private User $admin;

    private PaymentAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
        $this->enableMidtrans();
        $this->fakeMidtrans();
        $this->service = app(PaymentAttemptService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes = [], ?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create(array_merge([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 154600,
        ], $attributes));
    }

    private function snapCalls(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions'))->count();
    }

    public function test_opening_an_invoice_creates_an_attempt_for_the_whole_balance_with_a_snap_page(): void
    {
        $invoice = $this->invoice(['paid_amount' => 4600]);

        $attempt = $this->service->openFor($invoice, self::FINISH_URL);

        $this->assertSame('150000.00', $attempt->amount);
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $attempt->status);
        $this->assertSame($invoice->id, $attempt->invoice_id);
        $this->assertSame($invoice->company_id, $attempt->company_id);
        $this->assertSame('tok-'.$attempt->order_id, $attempt->snap_token);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.$attempt->order_id, $attempt->redirect_url);
        $this->assertTrue($attempt->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertMatchesRegularExpression('/^INB-[0-9A-HJKMNP-TV-Z]{26}$/', $attempt->order_id);
        $this->assertLessThanOrEqual(50, strlen($attempt->order_id));
        $this->assertSame(1, $this->snapCalls());
        $this->assertSame(1, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'created')->count());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions')
            && $request->data()['transaction_details'] === ['order_id' => $attempt->order_id, 'gross_amount' => 150000]
            && $request->data()['callbacks']['finish'] === self::FINISH_URL);
    }

    public function test_a_second_open_reuses_the_attempt_instead_of_creating_another_page(): void
    {
        $invoice = $this->invoice();

        $first = $this->service->openFor($invoice, self::FINISH_URL);
        $second = $this->service->openFor($invoice, self::FINISH_URL);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->redirect_url, $second->redirect_url);
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, $this->snapCalls());
    }

    public function test_an_expired_attempt_is_replaced_by_a_new_one(): void
    {
        $invoice = $this->invoice();
        $first = $this->service->openFor($invoice, self::FINISH_URL);
        $first->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->service->openFor($invoice, self::FINISH_URL);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->order_id, $second->order_id);
        $this->assertSame(2, $this->snapCalls());
    }

    public function test_a_balance_that_changed_gets_a_new_attempt_and_leaves_the_old_one_untouched(): void
    {
        $invoice = $this->invoice();
        $first = $this->service->openFor($invoice, self::FINISH_URL);
        BillingService::recordPayment($invoice, 54600, 'cash');

        $second = $this->service->openFor($invoice->fresh(), self::FINISH_URL);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('100000.00', $second->amount);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('154600.00', $first->fresh()->amount);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unpayable(): array
    {
        return [
            'draft' => [['status' => 'draft'], 'Tagihan belum diterbitkan.'],
            'paid' => [['status' => 'paid', 'paid_amount' => 154600], 'Tagihan sudah lunas.'],
            'cancelled' => [['status' => 'cancelled'], 'Tagihan sudah dibatalkan.'],
            'nothing left to pay' => [['status' => 'partial', 'paid_amount' => 154600], 'Tagihan tidak memiliki sisa yang harus dibayar.'],
            'fractional rupiah' => [['total' => 154600.5], 'Nominal tagihan memuat pecahan rupiah. Hubungi kami untuk membayar.'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('unpayable')]
    public function test_an_invoice_that_cannot_be_paid_online_is_refused_without_calling_midtrans(array $attributes, string $message): void
    {
        $invoice = $this->invoice($attributes);

        try {
            $this->service->openFor($invoice, self::FINISH_URL);
            $this->fail('The invoice should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame([$message], $e->errors()['pay']);
        }

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_open_gateway_failure_leaves_no_attempt_behind(): void
    {
        $invoice = $this->invoice();
        $this->midtransSnapReply = fn () => Http::response(['error_messages' => ['transaction_details.order_id has already been taken']], 400);

        try {
            $this->service->openFor($invoice, self::FINISH_URL);
            $this->fail('A rejected Snap call must surface as an error.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belum dapat disiapkan', $e->errors()['pay'][0]);
        }

        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
    }

    public function test_nothing_is_sent_and_nothing_is_stored_while_the_gateway_is_off(): void
    {
        $invoice = $this->invoice();
        $this->disableMidtrans();

        try {
            $this->service->openFor($invoice, self::FINISH_URL);
            $this->fail('A disabled gateway must refuse.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        Http::assertNothingSent();
        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_order_ids_stay_unique_across_companies_with_the_same_invoice_number(): void
    {
        $companyB = Company::factory()->create(['is_active' => true]);
        $invoiceA = $this->invoice(['number' => 'INV-2026-00001']);
        $invoiceB = $this->invoice(['number' => 'INV-2026-00001'], $companyB);

        $attemptA = $this->service->openFor($invoiceA, self::FINISH_URL);
        $userB = User::factory()->create(['company_id' => $companyB->id]);
        $this->actingAs($userB);
        $attemptB = $this->service->openFor($invoiceB, self::FINISH_URL);

        $this->assertSame($invoiceA->number, $invoiceB->number);
        $this->assertNotSame($attemptA->order_id, $attemptB->order_id);
        $this->assertMatchesRegularExpression('/^INB-[0-9A-HJKMNP-TV-Z]{26}$/', $attemptB->order_id);
        $this->assertSame($companyB->id, $attemptB->company_id);
    }

    public function test_a_new_order_id_is_inb_and_a_ulid_and_fits_the_midtrans_limit(): void
    {
        $first = PaymentAttempt::newOrderId();

        $this->assertMatchesRegularExpression('/^INB-[0-9A-HJKMNP-TV-Z]{26}$/', $first);
        $this->assertLessThanOrEqual(50, strlen($first));
        $this->assertNotSame($first, PaymentAttempt::newOrderId());
    }

    public function test_an_invoice_of_another_company_is_not_found(): void
    {
        $companyB = Company::factory()->create(['is_active' => true]);
        $foreign = $this->invoice(company: $companyB);

        try {
            $this->service->openFor($foreign, self::FINISH_URL);
            $this->fail('Another company\'s invoice must not open.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_the_financial_columns_of_an_attempt_are_immutable(): void
    {
        $attempt = $this->service->openFor($this->invoice(), self::FINISH_URL);

        foreach (['amount' => '1.00', 'order_id' => 'other', 'invoice_id' => $this->invoice()->id, 'company_id' => Company::factory()->create()->id] as $column => $value) {
            try {
                $attempt->forceFill([$column => $value])->save();
                $this->fail("{$column} must be immutable.");
            } catch (\LogicException) {
                $attempt->refresh();
            }
        }

        $this->expectException(\LogicException::class);
        $attempt->delete();
    }

    public function test_the_snap_token_and_page_url_never_leave_with_a_serialised_attempt(): void
    {
        $attempt = $this->service->openFor($this->invoice(), self::FINISH_URL);

        $this->assertStringNotContainsString($attempt->snap_token, $attempt->toJson());
        $this->assertStringNotContainsString('redirect_url', $attempt->toJson());
    }
}
