<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Where Midtrans sends a customer who paid from the mobile app: public, static, and never a source of payment state.
 */
class PaymentFinishedPageTest extends TestCase
{
    use InteractsWithMidtrans;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $customerUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->signInCustomer();
    }

    private function signInCustomer(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();

        [$this->customerUser, $this->customer] = $this->customerLogin();
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoiceOf(Customer $customer, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'total' => 154600,
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function propKeys(string $url): array
    {
        return array_keys($this->get($url)->assertOk()->viewData('page')['props']);
    }

    public function test_it_is_public_and_renders_the_static_page(): void
    {
        $this->get('/pembayaran/selesai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished'));

        $this->assertSame('/pembayaran/selesai', parse_url(route('payments.finished'), PHP_URL_PATH));
    }

    public function test_it_carries_no_props_of_its_own(): void
    {
        // What every Inertia page gets from the middleware, measured on a page that adds nothing.
        Route::middleware('web')->get('_probe/shared-props', fn () => Inertia::render('Portal/Payments/Finished'));

        $this->assertEqualsCanonicalizing($this->propKeys('/_probe/shared-props'), $this->propKeys('/pembayaran/selesai'));
    }

    public function test_it_ignores_every_query_parameter_midtrans_appends(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['number' => 'INV-FINISH-1']);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->status('paid')->create();
        $query = http_build_query(['order_id' => $attempt->order_id, 'status_code' => '200', 'transaction_status' => 'settlement', 'invoice' => $invoice->id]);

        $plain = $this->get('/pembayaran/selesai')->assertOk()->viewData('page');
        $decorated = $this->get('/pembayaran/selesai?'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished'))
            ->viewData('page');

        $this->assertSame(json_encode($plain['props']), json_encode($decorated['props']));
        foreach (['INV-FINISH-1', $attempt->order_id, '154600', 'settlement'] as $data) {
            $this->assertStringNotContainsString($data, (string) json_encode($decorated['props']));
        }
    }

    public function test_a_signed_in_customer_gets_the_same_page(): void
    {
        $this->actingAs($this->customerUser)
            ->get('/pembayaran/selesai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished'));
    }

    public function test_the_portal_return_page_still_needs_a_customer_login(): void
    {
        $this->get(route('portal.payments.return'))->assertRedirect(route('login'));
    }
}
