<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\InteractsWithMobileApi;

/**
 * K5 (rule Q16-b stays): a customer isolated for overdue bills is told which invoices still keep the service off, in the
 * subscription API and in the payment notice, so "Lunasi juga INV-..." and "Bayar semua" have something to stand on.
 */
class ReactivationHoldsTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->company = $this->apiCompany();
        $this->company->update(['settings' => ['auto_suspend_overdue_days' => 7]]);
    }

    /** A subscription of the customer's own, isolated at 14 Oct 2026 08:00 by the given source. */
    private function isolatedFor(Customer $customer, ?string $source = 'overdue', string $status = 'suspended'): ServiceSubscription
    {
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $customer->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'label' => Str::random(12),
            ])->id,
            'status' => $status,
            'activation_date' => now()->subMonths(3)->toDateString(),
        ]);
        $subscription->forceFill(['suspension_source' => $source, 'suspended_at' => $source === 'overdue' ? now()->subDay() : null])->save();

        return $subscription;
    }

    /** @return array{0: string, 1: Customer} */
    private function customerWithToken(): array
    {
        [$user, $customer] = $this->customerLogin($this->company);

        return [$this->issueToken($user, 'customer'), $customer];
    }

    /** @return list<array{id: string, number: string, due_date: string, sisa: string}> */
    private function holdsOf(string $token, ServiceSubscription $subscription): array
    {
        return $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $token)->assertOk()->json('data.reactivation_holds');
    }

    public function test_holds_list_invoices_keeping_suspension(): void
    {
        [$token, $customer] = $this->customerWithToken();
        $subscription = $this->isolatedFor($customer);
        $oldest = $this->invoiceFor($subscription, 40, ['number' => 'INV-OLD']);
        $partlyPaid = $this->invoiceFor($subscription, 10, ['number' => 'INV-MID', 'status' => 'partial', 'paid_amount' => 40000]);
        // Inside the grace, yet due before the isolation (rule Q16-b); the boundary day counts, the day after does not.
        $beforeIsolation = $this->invoiceFor($subscription, 3, ['number' => 'INV-NEW']);
        $onIsolationDay = $this->invoiceFor($subscription, 1, ['number' => 'INV-DAY']);
        $this->invoiceFor($subscription, 0, ['number' => 'INV-TODAY']);
        $this->invoiceFor($subscription, -5, ['number' => 'INV-FUTURE']);
        $this->invoiceFor($subscription, 40, ['number' => 'INV-PAID', 'status' => 'paid', 'paid_amount' => 100000]);
        $this->invoiceFor($subscription, 40, ['number' => 'INV-VOID', 'status' => 'cancelled']);
        $this->invoiceFor($subscription, 40, ['number' => 'INV-DRAFT', 'status' => 'draft']);
        $this->invoiceFor($this->isolatedFor($this->customerWithToken()[1]), 40, ['number' => 'INV-OTHER']);

        $expected = [
            ['id' => $oldest->public_id, 'number' => 'INV-OLD', 'due_date' => '2026-09-05', 'sisa' => '100000.00'],
            ['id' => $partlyPaid->public_id, 'number' => 'INV-MID', 'due_date' => '2026-10-05', 'sisa' => '60000.00'],
            ['id' => $beforeIsolation->public_id, 'number' => 'INV-NEW', 'due_date' => '2026-10-12', 'sisa' => '100000.00'],
            ['id' => $onIsolationDay->public_id, 'number' => 'INV-DAY', 'due_date' => '2026-10-14', 'sisa' => '100000.00'],
        ];

        $this->assertSame($expected, $this->holdsOf($token, $subscription), 'oldest due first, unpaid and due on or before the isolation or past the grace');

        $listed = collect($this->api('GET', 'customer/subscriptions?per_page=50', token: $token)->assertOk()->json('data'))->firstWhere('id', $subscription->public_id);
        $this->assertSame($expected, $listed['reactivation_holds'], 'the list carries them too');
    }

    public function test_a_legacy_isolation_without_a_moment_holds_by_the_grace_alone(): void
    {
        [$token, $customer] = $this->customerWithToken();
        $subscription = $this->isolatedFor($customer);
        $subscription->forceFill(['suspended_at' => null])->save();
        $past = $this->invoiceFor($subscription, 10, ['number' => 'INV-PAST']);
        $this->invoiceFor($subscription, 3, ['number' => 'INV-GRACE']);

        $this->assertSame([$past->public_id], array_column($this->holdsOf($token, $subscription), 'id'));
    }

    public function test_no_holds_when_not_overdue_suspension(): void
    {
        [$token, $customer] = $this->customerWithToken();
        $cases = [
            'manual' => $this->isolatedFor($customer, 'manual'),
            'legacy row without a source' => $this->isolatedFor($customer, null),
            'active' => $this->isolatedFor($customer, null, 'active'),
            'active with a leftover source' => $this->isolatedFor($customer, 'overdue', 'active'),
        ];

        foreach ($cases as $label => $subscription) {
            $this->invoiceFor($subscription, 40);

            $this->assertSame([], $this->holdsOf($token, $subscription), $label);
        }
    }

    public function test_holds_are_empty_when_the_company_reconnects_by_hand(): void
    {
        // Nothing "holds" a reconnection that staff do themselves: the customer is not promised one.
        $this->company->update(['settings' => ['auto_suspend_overdue_days' => 7, 'auto_reactivate_on_payment' => false]]);
        [$token, $customer] = $this->customerWithToken();
        $subscription = $this->isolatedFor($customer);
        $this->invoiceFor($subscription, 40);

        $this->assertSame([], $this->holdsOf($token, $subscription));
    }

    /**
     * The customer's notices as the app lists them, by the public id of the invoice each is about (two notices of the
     * same second have no fixed order).
     *
     * @return array<string, array<string, mixed>>
     */
    private function noticesByInvoice(string $token): array
    {
        return collect($this->api('GET', 'notifications', token: $token)->assertOk()->json('data'))->keyBy('subject.id')->all();
    }

    /** @return array<string, mixed> what the bell row of the invoice's payment notice stores */
    private function storedNotice(Customer $customer, Invoice $invoice): array
    {
        return $customer->notifications()->get()->sole(fn ($notice): bool => ($notice->data['invoice_id'] ?? null) === $invoice->public_id)->data;
    }

    public function test_the_list_reads_invoices_once_per_isolated_subscription_and_never_for_the_others(): void
    {
        [$token, $customer] = $this->customerWithToken();
        foreach ([$this->isolatedFor($customer), $this->isolatedFor($customer)] as $isolated) {
            $this->invoiceFor($isolated, 40);
        }
        foreach ([$this->isolatedFor($customer, 'manual'), $this->isolatedFor($customer, null, 'active'), $this->isolatedFor($customer, null, 'active')] as $other) {
            $this->invoiceFor($other, 40);
        }

        DB::enableQueryLog();
        $rows = $this->api('GET', 'customer/subscriptions?per_page=50', token: $token)->assertOk()->assertJsonCount(5, 'data')->json('data');
        $invoiceQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from `invoices`'));
        DB::disableQueryLog();

        $this->assertCount(2, $invoiceQueries);
        $this->assertSame([0, 0, 0, 1, 1], collect($rows)->map(fn (array $row): int => count($row['reactivation_holds']))->sort()->values()->all());
    }

    public function test_payment_with_remaining_holds_tells_the_customer(): void
    {
        [$token, $customer] = $this->customerWithToken();
        $subscription = $this->isolatedFor($customer);
        $first = $this->invoiceFor($subscription, 40, ['number' => 'INV-OLD']);
        $second = $this->invoiceFor($subscription, 10, ['number' => 'INV-MID']);

        CompanyService::runAs($this->company->id, fn () => BillingService::recordPayment($first, 100000, 'transfer', 'BANK-1'));

        $this->assertSame('suspended', $this->freshSubscription($subscription)->status, 'INV-MID still holds the isolation');
        $notice = $this->noticesByInvoice($token)[$first->public_id];
        $this->assertSame('payment_received', $notice['type']);
        $this->assertSame('Rp 100.000 untuk INV-OLD sudah kami terima. Layanan aktif kembali setelah INV-MID dilunasi.', $notice['message']);
        $stored = $this->storedNotice($customer, $first);
        $this->assertSame(['INV-MID'], $stored['holding_invoice_numbers']);
        $this->assertSame('INV-OLD', $stored['invoice_number']);

        CompanyService::runAs($this->company->id, fn () => BillingService::recordPayment($second, 100000, 'transfer', 'BANK-2'));

        $this->assertSame('active', $this->freshSubscription($subscription)->status, 'the last holder paid: the service is back');
        $this->assertSame('Rp 100.000 untuk INV-MID sudah kami terima. Terima kasih.', $this->noticesByInvoice($token)[$second->public_id]['message']);
        $this->assertArrayNotHasKey('holding_invoice_numbers', $this->storedNotice($customer, $second));
    }

    public function test_a_payment_for_an_invoice_without_holds_keeps_the_plain_notice(): void
    {
        [$token, $customer] = $this->customerWithToken();
        $subscription = $this->isolatedFor($customer, null, 'active');
        $invoice = $this->invoiceFor($subscription, 40);

        CompanyService::runAs($this->company->id, fn () => BillingService::recordPayment($invoice, 100000, 'transfer', 'BANK-3'));

        $this->assertSame("Rp 100.000 untuk {$invoice->number} sudah kami terima. Terima kasih.", $this->noticesByInvoice($token)[$invoice->public_id]['message']);
        $this->assertEqualsCanonicalizing(['type', 'invoice_id', 'invoice_number', 'amount'], array_keys($this->storedNotice($customer, $invoice)));
    }
}
