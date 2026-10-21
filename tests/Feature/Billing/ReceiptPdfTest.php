<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * F5-D09: the proof of payment is a kuitansi (who paid what, when and how), not the invoice with a stamp on it, and it
 * has no bank block. Staff download it with the PDF permission; the customer app gets the same document.
 */
class ReceiptPdfTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const REFUSED = 'Kuitansi hanya untuk tagihan yang sudah lunas.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoice(string $status = 'paid', array $attributes = [], ?Company $company = null, ?Customer $customer = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => ($customer ?? Customer::factory()->create(['company_id' => $companyId, 'name' => 'PT Maju Jaya']))->id,
            'number' => 'INV-2026-00042',
            'status' => $status,
            'total' => 154600,
            'paid_amount' => $status === 'paid' ? 154600 : 0,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function payment(Invoice $invoice, array $attributes = []): Payment
    {
        return Payment::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'amount' => '54600.00',
            'method' => 'cash',
            'reference' => 'KASIR-INTERNAL-7',
            'paid_at' => '2026-10-20 03:15:00',
            'received_by' => $this->admin->id,
            'notes' => 'catatan kasir',
            ...$attributes,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function permitted(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantPermissions($user, ['app.web', ...$permissions]);
    }

    /** The staff template reads and books invoices but holds no billing.pdf.view. */
    private function cashier(): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantRole($user, 'staff');
    }

    /**
     * What the receipt printed: the HTML of every `pdf.receipt` view rendered while $work ran.
     *
     * @return list<string>
     */
    private function printed(callable $work): array
    {
        $views = [];
        View::composer('pdf.receipt', function (ViewContract $view) use (&$views): void {
            $views[] = $view;
        });
        $work();

        return array_map(fn (ViewContract $view): string => view($view->name(), $view->getData())->render(), $views);
    }

    public function test_staff_download_the_receipt_of_a_paid_invoice_as_a_pdf(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, ['amount' => '154600.00']);

        $response = $this->get(route('admin.invoices.receipt', $invoice))->assertOk();

        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('Kuitansi-INV-2026-00042.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_the_receipt_says_who_paid_what_when_and_how_and_prints_no_bank_block(): void
    {
        $this->admin->company->update([
            'name' => 'Inbils Demo',
            'timezone' => 'Asia/Jakarta',
            'settings' => [...($this->admin->company->settings ?? []), 'bank_account_info' => 'BCA 123-INTERNAL-REKENING'],
        ]);
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $this->admin->company_id);
        $invoice = $this->invoice();
        $this->payment($invoice);
        $this->payment($invoice, ['amount' => '100000.00', 'method' => 'midtrans', 'reference' => 'INB-01JABCDEF', 'paid_at' => '2026-10-21 18:30:00']);
        $reversed = $this->payment($invoice, ['amount' => '9999.00', 'reference' => 'DIBATALKAN-REF']);
        Payment::withoutCompany()->whereKey($reversed->id)->toBase()->update(['cancelled_at' => now()]);

        [$html] = $this->printed(fn () => $this->get(route('admin.invoices.receipt', $invoice))->assertOk());

        foreach (['KUITANSI PEMBAYARAN', 'No. KWT/INV-2026-00042', 'Telah diterima dari', 'PT Maju Jaya', 'Untuk pembayaran tagihan INV-2026-00042', 'Inbils Demo'] as $printed) {
            $this->assertStringContainsString($printed, $html);
        }
        // Dates in the company's zone (03:15 UTC is 10:15 in Jakarta, 18:30 UTC is 01:30 the next day), oldest payment
        // first, and the day of the last one under the title.
        $this->assertStringContainsString('20/10/2026 10:15', $html);
        $this->assertStringContainsString('22/10/2026 01:30', $html);
        $this->assertStringContainsString('Tanggal: 22/10/2026', $html);
        $this->assertLessThan(strpos($html, '22/10/2026 01:30'), strpos($html, '20/10/2026 10:15'));
        // Method as people say it, amounts as rupiah, and the total with its words.
        foreach (['Tunai', 'Pembayaran online', 'Rp 54.600', 'Rp 100.000', 'Total dibayar', 'Rp 154.600', 'Terbilang: Seratus lima puluh empat ribu enam ratus rupiah'] as $printed) {
            $this->assertStringContainsString($printed, $html);
        }
        // The gateway order id is printable. What staff typed on a cash payment is not, nor is a reversed payment or the bank.
        $this->assertStringContainsString('INB-01JABCDEF', $html);
        foreach (['KASIR-INTERNAL-7', 'catatan kasir', 'DIBATALKAN-REF', 'Rp 9.999', 'BCA 123-INTERNAL-REKENING', 'Pembayaran:', 'Jatuh tempo', 'Sisa', '—'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "{$absent} must not be on a receipt");
        }
    }

    /** @return array<string, array{string}> */
    public static function unpaidStatuses(): array
    {
        return ['draft' => ['draft'], 'sent' => ['sent'], 'partial' => ['partial'], 'overdue' => ['overdue'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('unpaidStatuses')]
    public function test_only_a_paid_invoice_has_a_receipt(string $status): void
    {
        $invoice = $this->invoice($status);
        $this->payment($invoice);

        $this->getJson(route('admin.invoices.receipt', $invoice))
            ->assertUnprocessable()
            ->assertJsonPath('message', self::REFUSED);
    }

    public function test_the_receipt_needs_the_pdf_permission_on_top_of_seeing_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, ['amount' => '154600.00']);
        $cashier = $this->cashier();

        $this->actingAs($cashier)->get(route('admin.invoices.receipt', $invoice))->assertForbidden();

        // The PDF permission alone shows no invoice, so it prints none.
        $this->actingAs($this->permitted(['billing.pdf.view']))->get(route('admin.invoices.receipt', $invoice))->assertForbidden();

        // A permission is what counts, not a role name.
        $this->actingAs($this->permitted(['billing.view', 'billing.pdf.view']))->get(route('admin.invoices.receipt', $invoice))->assertOk();

        // A refusal for a missing permission does not tell whether the invoice is paid.
        $open = $this->invoice('sent', ['number' => 'INV-2026-00043']);
        $this->actingAs($cashier)->getJson(route('admin.invoices.receipt', $open))->assertForbidden();
    }

    public function test_another_companys_invoice_has_no_receipt_to_download(): void
    {
        $foreign = $this->invoice(company: Company::factory()->create(['is_active' => true]));

        $this->get(route('admin.invoices.receipt', $foreign))->assertNotFound();
    }

    public function test_the_page_tells_the_ui_who_may_download_a_receipt(): void
    {
        $invoice = $this->invoice();

        $this->get(route('admin.invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->where('can.receipt', true));

        $this->actingAs($this->cashier())->get(route('admin.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page->where('can.receipt', false));
    }

    public function test_the_customer_app_gets_the_receipt_not_the_invoice_and_no_tax_id(): void
    {
        [$customerUser, $customer] = $this->customerLogin($this->admin->company);
        $customer->update(['tax_id' => '01.234.567.8-901.000']);
        $invoice = $this->invoice(customer: $customer);
        $this->payment($invoice, ['amount' => '154600.00', 'method' => 'transfer']);
        $used = [];
        View::composer(['pdf.invoice', 'pdf.receipt'], function (ViewContract $view) use (&$used): void {
            $used[$view->name()] = $view->getData()['party']['customer_tax_id'];
        });

        $this->api('GET', "customer/invoices/{$invoice->public_id}/receipt", token: $this->issueToken($customerUser, 'customer'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(['pdf.receipt' => null], $used, 'the receipt, never the invoice, and the customer tax id never reaches the app');

        // Staff see the tax id as it was printed on the invoice.
        $used = [];
        $this->actingAs($this->admin)->get(route('admin.invoices.receipt', $invoice))->assertOk();
        $this->assertSame(['pdf.receipt' => '01.234.567.8-901.000'], $used);
    }
}
