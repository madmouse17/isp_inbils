<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Modules\Billing\Notifications\PaymentReceivedNotification;
use Modules\Billing\Services\BillingService;
use Modules\Service\Database\Factories\ServicePackageFactory;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithWhatsApp;

class InvoiceWhatsAppNotificationTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->company->update(['name' => 'PT Contoh Net']);
        $this->whatsAppEnabledFor($this->company);
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->travelTo('2026-06-01 02:00:00'); // 09:00 in Jakarta, outside the quiet window
    }

    private function subscription(): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'label' => 'Main'])->id,
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create($attributes + [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'number' => 'INV-2026-00012',
            'status' => 'sent',
            'total' => 250000,
            'paid_amount' => 0,
            'due_date' => '2026-06-15',
        ]);
    }

    /** @return list<string> the template body parameters of the nth Graph request */
    private function parameters(int $nth = 0): array
    {
        return array_column($this->graphRequests()->values()->get($nth)->data()['template']['components'][0]['parameters'], 'text');
    }

    public function test_recurring_generation_sends_one_invoice_issued_message_after_commit(): void
    {
        $this->subscription();

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::query()->where('type', 'recurring')->sole();
        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(fn (Request $request): bool => $request->data()['to'] === '6281234567890' && $request->data()['template']['name'] === 'tagihan_terbit');
        $this->assertSame(['PT Contoh Net', $invoice->number, 'Rp 333.000', '15 Jun 2026', route('portal.invoices.show', $invoice)], $this->parameters());

        $row = $this->whatsAppRows()->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('invoice_issued', $row->event);
        $this->assertSame('invoice', $row->subject_type);
        $this->assertSame($invoice->id, $row->subject_id);
        $this->assertSame('invoice_issued:'.$invoice->id, $row->dedupe_key);
        $this->assertSame($this->customer->id, $row->recipient_id);
    }

    public function test_a_forced_rollback_after_the_insert_sends_nothing(): void
    {
        $this->subscription();

        try {
            DB::transaction(function (): void {
                BillingService::generateForPeriod('2026-06');

                throw new RuntimeException('abort after the insert');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Invoice::query()->count());
        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_rerunning_the_generation_sends_nothing_new(): void
    {
        $this->subscription();

        BillingService::generateForPeriod('2026-06');
        BillingService::generateForPeriod('2026-06');

        $this->assertCount(1, $this->graphRequests());
        $this->assertCount(1, $this->whatsAppRows());
    }

    public function test_creating_a_draft_sends_nothing_and_sending_it_sends_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), ['customer_id' => $this->customer->id, 'due_date' => '2026-06-20'])->assertRedirect();
        $draft = Invoice::query()->sole();
        $draft->forceFill(['total' => 100000, 'status' => 'draft'])->save();

        Http::assertNothingSent();
        $this->assertSame('draft', $draft->status);

        $this->actingAs($this->admin)->post(route('admin.invoices.send', $draft))->assertRedirect();

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame(['PT Contoh Net', $draft->number, 'Rp 100.000', '20 Jun 2026', route('portal.invoices.show', $draft)], $this->parameters());
        $this->assertSame('invoice_issued:'.$draft->id, $this->whatsAppRows()->sole()->dedupe_key);
    }

    public function test_sending_a_draft_twice_is_refused_and_does_not_message_twice(): void
    {
        $draft = $this->invoice(['status' => 'draft']);

        BillingService::send($draft);
        try {
            BillingService::send($draft);
        } catch (\Throwable) {
        }

        $this->assertCount(1, $this->graphRequests());
    }

    public function test_a_customer_without_consent_is_logged_as_skipped_and_never_called(): void
    {
        $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();
        $this->subscription();

        BillingService::generateForPeriod('2026-06');

        Http::assertNothingSent();
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_consent', $row->skip_reason);
    }

    public function test_a_manual_payment_sends_one_payment_received_message_with_the_remaining_balance(): void
    {
        $invoice = $this->invoice();

        $payment = BillingService::recordPayment($invoice, 100000, 'cash');

        $this->assertCount(1, $this->graphRequests());
        Http::assertSent(fn (Request $request): bool => $request->data()['template']['name'] === 'pembayaran_diterima');
        $this->assertSame(['PT Contoh Net', 'INV-2026-00012', 'Rp 100.000', 'Rp 150.000', '01 Jun 2026'], $this->parameters());
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('payment_received:'.$payment->id, $row->dedupe_key);
        $this->assertSame('invoice', $row->subject_type);
        $this->assertSame($invoice->id, $row->subject_id);
    }

    public function test_the_payment_date_is_shown_in_company_time(): void
    {
        $invoice = $this->invoice();
        $this->travelTo('2026-06-01 20:30:00'); // 03:30 on 2 June in Jakarta

        BillingService::recordPayment($invoice, 250000, 'transfer');

        $this->assertSame(['PT Contoh Net', 'INV-2026-00012', 'Rp 250.000', 'Rp 0', '02 Jun 2026'], $this->parameters());
    }

    public function test_the_staff_payment_form_sends_the_receipt(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->admin)->post(route('admin.invoices.payments.store', $invoice), ['amount' => 50000, 'method' => 'transfer'])->assertRedirect();

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame('Rp 200.000', $this->parameters()[3]);
    }

    public function test_a_midtrans_settlement_sends_one_payment_received_message(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();
        $invoice = $this->invoice(['total' => 100000]);
        $attempt = PaymentAttemptFactory::new()->forInvoice($invoice)->create();
        $notification = $this->midtransPayload($attempt);
        $this->midtransStatusReturns($notification);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame(['PT Contoh Net', 'INV-2026-00012', 'Rp 100.000', 'Rp 0', '01 Jun 2026'], $this->parameters());
        $this->assertSame(1, Payment::withoutCompany()->count());
    }

    public function test_a_rejected_payment_sends_nothing(): void
    {
        $invoice = $this->invoice(['total' => 100000]);

        try {
            BillingService::recordPayment($invoice, 100001, 'cash');
            $this->fail('the overpayment must be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->actingAs($this->admin)->post(route('admin.invoices.payments.store', $invoice), ['amount' => 0, 'method' => 'cash'])->assertInvalid(['amount']);

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_a_payment_that_is_reversed_before_the_message_goes_out_sends_nothing(): void
    {
        $invoice = $this->invoice();
        Queue::fake();
        $payment = BillingService::recordPayment($invoice, 100000, 'cash');
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_active' => true])); // maker/checker: not the recorder
        BillingService::reversePayment($payment, 'wrong amount');
        $job = Queue::pushed(SendQueuedNotifications::class)->sole();

        $job->handle(app(ChannelManager::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_an_invoice_issued_message_waits_for_the_end_of_quiet_hours_and_a_receipt_does_not(): void
    {
        Queue::fake();
        $this->subscription();
        $this->travelTo('2026-06-01 15:00:00'); // 22:00 in Jakarta

        BillingService::generateForPeriod('2026-06');
        BillingService::recordPayment($this->invoice(), 100000, 'cash');

        $issued = Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof InvoiceIssuedNotification)->sole();
        $receipt = Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof PaymentReceivedNotification)->sole();
        $this->assertEquals(CarbonImmutable::parse('2026-06-02 07:00:00', 'Asia/Jakarta'), $issued->delay);
        $this->assertNull($receipt->delay);
        $this->assertSame('whatsapp', $issued->queue);
        $this->assertSame('whatsapp', $receipt->queue);
    }

    public function test_an_invoice_that_was_paid_or_cancelled_while_the_message_waited_is_not_announced(): void
    {
        $paid = $this->invoice(['number' => 'INV-PAID', 'status' => 'paid', 'paid_amount' => 250000]);
        $cancelled = $this->invoice(['number' => 'INV-CANCELLED', 'status' => 'cancelled']);
        $draft = $this->invoice(['number' => 'INV-DRAFT', 'status' => 'draft']);

        foreach ([$paid, $cancelled, $draft] as $invoice) {
            Notification::sendNow($this->customer, new InvoiceIssuedNotification($this->company->id, $invoice->id));
        }

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_the_notification_job_carries_ids_only(): void
    {
        Queue::fake();
        $invoice = $this->invoice(['status' => 'draft']);

        BillingService::send($invoice);

        $job = Queue::pushed(SendQueuedNotifications::class)->sole();
        $payload = serialize($job);
        foreach (['PT Contoh Net', 'INV-2026-00012', '250000', '0812', $this->customer->name] as $personal) {
            $this->assertStringNotContainsString($personal, $payload);
        }
        $this->assertSame($invoice->id, $job->notification->invoiceId);
        $this->assertSame($this->company->id, $job->notification->companyId);
    }

    public function test_a_failing_whatsapp_dispatch_never_breaks_the_billing_flow(): void
    {
        $this->subscription();
        Exceptions::fake();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('queue is down'));

        $result = BillingService::generateForPeriod('2026-06');

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Invoice::query()->where('type', 'recurring')->count());
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'queue is down');
    }

    public function test_two_companies_with_the_same_invoice_number_message_only_their_own_customer(): void
    {
        $other = Company::factory()->create(['name' => 'CV Lain', 'settings' => ['whatsapp_enabled' => true]]);
        $otherCustomer = $this->consentedCustomer($other, '0813-9999-0001');
        $mine = $this->invoice(['number' => 'INV-DUP-1']);
        $theirs = InvoiceFactory::new()->create(['company_id' => $other->id, 'customer_id' => $otherCustomer->id, 'number' => 'INV-DUP-1', 'status' => 'draft', 'total' => 500000, 'due_date' => '2026-06-15']);

        CompanyService::runAs($other->id, fn () => BillingService::send($theirs));
        BillingService::recordPayment($mine, 50000, 'cash');

        $this->assertCount(2, $this->graphRequests());
        $byRecipient = $this->graphRequests()->mapWithKeys(fn (Request $request): array => [$request->data()['to'] => $request->data()['template']['name']]);
        $this->assertSame(['6281399990001' => 'tagihan_terbit', '6281234567890' => 'pembayaran_diterima'], $byRecipient->all());
        $this->assertSame([$other->id], $this->whatsAppRows($other->id)->pluck('company_id')->unique()->values()->all());
        $this->assertSame(1, WhatsAppMessage::withoutCompany()->where('company_id', $this->company->id)->count());
    }
}
