<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\PaymentAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Where Snap sends a customer who paid from the mobile app (F5-U15, F5-D11, F5-D17). It is public and it is never a
 * source of payment state: `transaction_status` only picks one of four sentences, and nothing else of the address is read or repeated.
 */
class PaymentFinishPageTest extends TestCase
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
        $this->enableMidtrans();
        $this->fakeMidtrans();
        [$this->customerUser, $this->customer] = $this->customerLogin();
    }

    /** @return array<string, array{string|null, string}> */
    public static function snapStatuses(): array
    {
        return [
            'settlement' => ['settlement', 'success'],
            'capture' => ['capture', 'success'],
            'pending' => ['pending', 'pending'],
            'authorize' => ['authorize', 'pending'],
            'deny' => ['deny', 'failed'],
            'cancel' => ['cancel', 'failed'],
            'expire' => ['expire', 'failed'],
            'failure' => ['failure', 'failed'],
            'a refund is not an outcome of paying' => ['refund', 'unknown'],
            'capitals are not a status' => ['SETTLEMENT', 'unknown'],
            'trailing spaces are trimmed like any input' => ['settlement ', 'success'],
            'nothing' => [null, 'unknown'],
            'an empty status' => ['', 'unknown'],
            'a made-up one' => ['<script>alert(1)</script>', 'unknown'],
        ];
    }

    #[DataProvider('snapStatuses')]
    public function test_the_transaction_status_picks_one_of_four_sentences(?string $status, string $outcome): void
    {
        $this->get('/pembayaran/selesai'.($status === null ? '' : '?'.http_build_query(['transaction_status' => $status])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished')->where('outcome', $outcome));
    }

    public function test_a_status_that_is_not_one_string_is_not_a_status(): void
    {
        $this->get('/pembayaran/selesai?transaction_status[]=settlement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'unknown'));
        $this->get('/pembayaran/selesai?transaction_status[a]=settlement&transaction_status[b]=cancel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'unknown'));
    }

    public function test_it_is_public_and_keeps_its_route(): void
    {
        $this->get('/pembayaran/selesai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished'));

        $this->assertSame('/pembayaran/selesai', parse_url(route('payments.finished'), PHP_URL_PATH));
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('payments.finished')->methods());
    }

    public function test_it_never_echoes_the_address_and_never_looks_anything_up(): void
    {
        $invoice = InvoiceFactory::new()->create(['company_id' => $this->customer->company_id, 'customer_id' => $this->customer->id, 'status' => 'sent', 'total' => 154600, 'number' => 'INV-FINISH-1']);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->status('paid')->create();
        $query = http_build_query([
            'order_id' => $attempt->order_id,
            'status_code' => '200',
            'gross_amount' => '154600.00',
            'transaction_status' => 'settlement',
            'invoice' => $invoice->public_id,
            'name' => '<img src=x onerror=alert(1)>',
        ]);

        DB::enableQueryLog();
        $response = $this->get('/pembayaran/selesai?'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('outcome', 'success'));
        $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        // Nothing of the address comes back as page data: not the order, the invoice, the amount, nor the markup somebody put in it.
        $props = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('154600', $props);
        foreach ([$attempt->order_id, 'INV-FINISH-1', $invoice->public_id, '<img', 'onerror', 'alert'] as $echoed) {
            $this->assertStringNotContainsString($echoed, $props);
        }
        // Inertia carries the address it was asked for as the page's own URL, escaped; the markup in it never becomes markup.
        $this->assertStringNotContainsString('<img', $response->getContent());
        // The order, the invoice and the customer are not even read.
        foreach (['`payment_attempts`', '`invoices`', '`customers`'] as $table) {
            $this->assertStringNotContainsString($table, $tables, "the finish page must not read {$table}");
        }
    }

    public function test_it_changes_nothing_for_any_address(): void
    {
        $invoice = InvoiceFactory::new()->create(['company_id' => $this->customer->company_id, 'customer_id' => $this->customer->id, 'status' => 'sent', 'total' => 154600]);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->create();

        $this->get('/pembayaran/selesai?'.http_build_query(['order_id' => $attempt->order_id, 'status_code' => '200', 'transaction_status' => 'settlement']))->assertOk();

        $this->assertSame('pending', $attempt->fresh()->status, 'a customer who claims to have paid has paid nothing');
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_the_app_link_is_the_play_store_listing_once_the_platform_set_one(): void
    {
        $this->get('/pembayaran/selesai')->assertInertia(fn (Assert $page) => $page->where('appUrl', null));

        SettingService::set('play_store_url_customer', 'https://play.google.com/store/apps/details?id=id.inbils.customer');
        $this->get('/pembayaran/selesai')
            ->assertInertia(fn (Assert $page) => $page->where('appUrl', 'https://play.google.com/store/apps/details?id=id.inbils.customer'));

        // A setting that is not a Play Store address is not offered as a link.
        SettingService::set('play_store_url_customer', 'https://evil.example/app');
        $this->get('/pembayaran/selesai')->assertInertia(fn (Assert $page) => $page->where('appUrl', null));
    }

    public function test_a_signed_in_customer_gets_the_same_page(): void
    {
        $this->actingAs($this->customerUser)
            ->get('/pembayaran/selesai?transaction_status=cancel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Payments/Finished')->where('outcome', 'failed'));
    }

    public function test_the_portal_return_page_still_needs_a_customer_login(): void
    {
        $this->get(route('portal.payments.return'))->assertRedirect(route('login'));
    }
}
