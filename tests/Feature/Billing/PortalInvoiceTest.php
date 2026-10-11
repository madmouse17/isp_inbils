<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Notifications\CustomerAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class PortalInvoiceTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private Company $company;

    private User $customerUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($admin->company_id);
        $this->enableMidtrans();
        $this->fakeMidtrans();

        // The seeder may not carry the portal permissions yet; findOrCreate is idempotent either way.
        $role = Role::findOrCreate('customer', 'web');
        $role->givePermissionTo(
            Permission::findOrCreate('portal.invoice.view', 'web'),
            Permission::findOrCreate('portal.invoice.pay', 'web'),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$this->customerUser, $this->customer] = $this->customerLogin($this->company);
    }

    /** @return array{0: User, 1: Customer} */
    private function customerLogin(Company $company, array $user = []): array
    {
        $login = User::factory()->create(array_merge(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true], $user));
        $login->assignRole('customer');
        $customer = Customer::factory()->create(['company_id' => $company->id, 'user_id' => $login->id]);

        return [$login, $customer];
    }

    /** @param array<string, mixed> $attributes */
    private function invoiceOf(Customer $customer, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create(array_merge([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'total' => 154600,
        ], $attributes));
    }

    /** @return list<string> */
    private function numbers(array $props): array
    {
        return array_column($props['invoices']['data'], 'number');
    }

    public function test_a_customer_lists_only_their_own_issued_invoices(): void
    {
        $this->invoiceOf($this->customer, ['number' => 'INV-MINE-SENT']);
        $this->invoiceOf($this->customer, ['number' => 'INV-MINE-PAID', 'status' => 'paid', 'paid_amount' => 154600]);
        $this->invoiceOf($this->customer, ['number' => 'INV-MINE-CANCELLED', 'status' => 'cancelled']);
        $this->invoiceOf($this->customer, ['number' => 'INV-MINE-DRAFT', 'status' => 'draft']);
        [, $other] = $this->customerLogin($this->company);
        $this->invoiceOf($other, ['number' => 'INV-OTHER-SENT']);

        $response = $this->actingAs($this->customerUser)->get(route('portal.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Invoices/Index')
                ->has('invoices.data', 3)
                ->where('paymentEnabled', true));

        $this->assertEqualsCanonicalizing(['INV-MINE-SENT', 'INV-MINE-PAID', 'INV-MINE-CANCELLED'], $this->numbers($response->viewData('page')['props']));
    }

    public function test_the_invoice_detail_is_customer_safe(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['notes' => 'INTERNAL: customer owes us a favour', 'status' => 'partial', 'paid_amount' => 54600]);
        $invoice->items()->create(['company_id' => $invoice->company_id, 'description' => 'Internet 20 Mbps', 'quantity' => 1, 'unit_price' => 154600, 'discount_amount' => 0, 'tax_rate' => 0, 'line_total' => 154600]);
        Payment::create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'amount' => '54600.00', 'method' => 'transfer', 'reference' => 'BANK-REF-SECRET', 'paid_at' => now(), 'received_by' => User::factory()->create()->id, 'notes' => 'cash desk note']);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->create();

        $response = $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Invoices/Show')
                ->where('invoice.data.number', $invoice->number)
                ->where('invoice.data.sisa', '100000.00')
                ->has('invoice.data.items', 1)
                ->has('invoice.data.payments', 1)
                ->where('invoice.data.payments.0.method', 'transfer')
                ->where('payment', ['enabled' => true, 'can_pay' => true, 'blocked_reason' => null]));

        $json = json_encode($response->viewData('page')['props']);
        foreach (['INTERNAL', 'BANK-REF-SECRET', 'cash desk note', $attempt->snap_token, $attempt->order_id, 'redirect_url', 'snap_token', self::FAKE_SERVER_KEY, 'SB-Mid-client', 'received_by', 'created_by', 'cancel_reason'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked into the portal props");
        }
    }

    public function test_reversed_payments_are_not_shown_to_the_customer(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        Payment::create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'amount' => '1000.00', 'method' => 'cash', 'reference' => 'R-1', 'paid_at' => now(), 'received_by' => User::factory()->create()->id]);
        Payment::withoutCompany()->where('reference', 'R-1')->toBase()->update(['cancelled_at' => now()]);

        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page->has('invoice.data.payments', 0));
    }

    public function test_another_customers_invoice_is_not_found_never_forbidden(): void
    {
        [, $other] = $this->customerLogin($this->company);
        $theirs = $this->invoiceOf($other);

        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $theirs->id))->assertNotFound();
        $this->actingAs($this->customerUser)->postJson(route('portal.invoices.pay', $theirs->id))->assertNotFound();

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_draft_and_a_non_numeric_id_are_not_found(): void
    {
        $draft = $this->invoiceOf($this->customer, ['status' => 'draft']);

        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $draft->id))->assertNotFound();
        $this->actingAs($this->customerUser)->get('/portal/invoices/abc')->assertNotFound();
    }

    public function test_a_customer_of_another_company_cannot_reach_this_companys_invoices(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $companyB = Company::factory()->create(['is_active' => true]);
        [$userB, $customerB] = $this->customerLogin($companyB);
        $this->invoiceOf($customerB, ['number' => 'INV-B-ONLY']);

        $this->actingAs($userB)->get(route('portal.invoices.show', $invoice->id))->assertNotFound();
        $this->actingAs($userB)->postJson(route('portal.invoices.pay', $invoice->id))->assertNotFound();
        $this->actingAs($userB)->get(route('portal.invoices.index'))
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', 'INV-B-ONLY'));
        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
    }

    public function test_staff_without_the_customer_role_is_forbidden_on_every_portal_route(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $admin = User::query()->where('company_id', $this->company->id)->role('admin')->firstOrFail();
        $admin->givePermissionTo(['portal.invoice.view', 'portal.invoice.pay']);

        foreach ([
            ['get', route('portal.invoices.index')],
            ['get', route('portal.invoices.show', $invoice->id)],
            ['post', route('portal.invoices.pay', $invoice->id)],
            ['get', route('portal.payments.return')],
            ['get', route('portal.password.edit')],
        ] as [$method, $url]) {
            $this->actingAs($admin)->{$method.'Json'}($url)->assertForbidden();
        }

        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_a_customer_login_without_a_customer_record_is_forbidden(): void
    {
        $orphan = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now()]);
        $orphan->assignRole('customer');

        $this->actingAs($orphan)->get(route('portal.invoices.index'))->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('portal.invoices.index'))->assertRedirect(route('login'));
        $this->post(route('portal.invoices.pay', 1))->assertRedirect(route('login'));
    }

    public function test_a_customer_cannot_use_the_admin_billing_routes(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        $this->actingAs($this->customerUser)->get(route('admin.invoices.index'))->assertForbidden();
        $this->actingAs($this->customerUser)->get(route('admin.invoices.show', $invoice->id))->assertForbidden();
        $this->actingAs($this->customerUser)->postJson(route('admin.invoices.payments.store', $invoice->id), ['amount' => 1000, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($this->customerUser)->get(route('admin.invoices.pdf', $invoice->id))->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_customer_without_the_pay_permission_can_read_but_not_pay(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        Role::findByName('customer', 'web')->revokePermissionTo('portal.invoice.pay');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->customerUser)->get(route('portal.invoices.index'))->assertOk();
        $this->actingAs($this->customerUser)->postJson(route('portal.invoices.pay', $invoice->id))->assertForbidden();

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_customer_without_the_view_permission_sees_nothing(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        Role::findByName('customer', 'web')->revokePermissionTo('portal.invoice.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->customerUser)->get(route('portal.invoices.index'))->assertForbidden();
        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))->assertForbidden();
        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))->assertForbidden();
    }

    public function test_paying_sends_the_browser_to_the_hosted_page_and_a_second_click_reuses_it(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        $first = $this->actingAs($this->customerUser)->post(route('portal.invoices.pay', $invoice->id), [], ['X-Inertia' => 'true']);
        $attempt = PaymentAttempt::query()->sole();
        $first->assertStatus(409)->assertHeader('X-Inertia-Location', $attempt->redirect_url);

        $second = $this->actingAs($this->customerUser)->post(route('portal.invoices.pay', $invoice->id));
        $second->assertRedirect($attempt->redirect_url);

        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame('154600.00', $attempt->amount);
        $this->assertStringStartsWith('https://app.sandbox.midtrans.com/snap/v4/redirection/', $attempt->redirect_url);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions'))->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions')
            && $request->data()['callbacks']['finish'] === route('portal.payments.return'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unpayable(): array
    {
        return [
            'paid' => [['status' => 'paid', 'paid_amount' => 154600], 'Tagihan sudah lunas.'],
            'cancelled' => [['status' => 'cancelled'], 'Tagihan sudah dibatalkan.'],
            'fractional rupiah' => [['total' => 154600.75], 'Nominal tagihan memuat pecahan rupiah. Hubungi kami untuk membayar.'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('unpayable')]
    public function test_an_invoice_that_cannot_be_paid_online_answers_422_with_a_clear_message(array $attributes, string $message): void
    {
        $invoice = $this->invoiceOf($this->customer, $attributes);

        $this->actingAs($this->customerUser)->postJson(route('portal.invoices.pay', $invoice->id))
            ->assertUnprocessable()
            ->assertJsonPath('errors.pay.0', $message);

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_invoice_page_says_why_paying_is_blocked(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'cancelled']);

        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page->where('payment', ['enabled' => true, 'can_pay' => false, 'blocked_reason' => 'Tagihan sudah dibatalkan.']));
    }

    public function test_pay_is_hidden_and_the_endpoint_is_404_while_the_gateway_is_off(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->disableMidtrans();

        $this->actingAs($this->customerUser)->postJson(route('portal.invoices.pay', $invoice->id))->assertNotFound();
        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page->where('payment.enabled', false)->where('payment.can_pay', false));
        $this->actingAs($this->customerUser)->get(route('portal.invoices.index'))
            ->assertInertia(fn (Assert $page) => $page->where('paymentEnabled', false));

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_pay_endpoint_is_rate_limited_per_user(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->customerUser)->post(route('portal.invoices.pay', $invoice->id))->assertRedirect();
        }

        $this->actingAs($this->customerUser)->post(route('portal.invoices.pay', $invoice->id))->assertStatus(429);
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_a_flagged_account_is_sent_to_the_set_password_page_before_any_invoice_page(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->customerUser->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->customerUser)->get(route('portal.invoices.index'))->assertRedirect(route('portal.password.edit'));
        $this->actingAs($this->customerUser)->get(route('portal.invoices.show', $invoice->id))->assertRedirect(route('portal.password.edit'));
        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))->assertRedirect(route('portal.password.edit'));
        $this->actingAs($this->customerUser)->post(route('portal.invoices.pay', $invoice->id))->assertRedirect(route('portal.password.edit'));
        $this->actingAs($this->customerUser)->get(route('portal.password.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Password'));

        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_phone_number_password_cannot_clear_the_flag(): void
    {
        $this->customerUser->forceFill(['password' => Hash::make('0800005195'), 'must_change_password' => true])->save();

        // The old "prove it with the current password" endpoint is gone: only a mailbox-proven reset clears the flag.
        $this->assertNull(app('router')->getRoutes()->getByName('portal.password.update'));
        foreach (['put', 'patch', 'post'] as $method) {
            $this->actingAs($this->customerUser)->{$method.'Json'}('/portal/password', [
                'current_password' => '0800005195',
                'password' => 'N3w-Passw0rd-xyz',
                'password_confirmation' => 'N3w-Passw0rd-xyz',
            ])->assertStatus(405);
        }

        $fresh = $this->customerUser->fresh();
        $this->assertTrue((bool) $fresh->getAttribute('must_change_password'));
        $this->assertTrue(Hash::check('0800005195', $fresh->password));
        $this->actingAs($fresh)->get(route('portal.invoices.index'))->assertRedirect(route('portal.password.edit'));
    }

    public function test_a_flagged_customer_can_ask_for_the_set_password_link_again_and_it_is_throttled(): void
    {
        Notification::fake();
        $this->customerUser->forceFill(['must_change_password' => true])->save();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->customerUser)->from(route('portal.password.edit'))->post(route('portal.password.link'))
                ->assertRedirect(route('portal.password.edit'))
                ->assertSessionHas('success');
        }
        Notification::assertSentToTimes($this->customerUser, CustomerAccountCreated::class, 3);

        $this->actingAs($this->customerUser)->post(route('portal.password.link'))->assertStatus(429);
        Notification::assertSentToTimes($this->customerUser, CustomerAccountCreated::class, 3);
    }

    public function test_an_account_that_is_not_flagged_has_no_use_for_the_link_or_the_page(): void
    {
        Notification::fake();

        $this->actingAs($this->customerUser)->post(route('portal.password.link'))->assertForbidden();
        $this->actingAs($this->customerUser)->get(route('portal.password.edit'))->assertRedirect(route('portal.invoices.index'));

        Notification::assertNothingSent();
    }

    public function test_an_inactive_flagged_account_is_not_sent_a_link(): void
    {
        Notification::fake();
        $this->customerUser->forceFill(['must_change_password' => true, 'is_active' => false])->save();

        $this->actingAs($this->customerUser)->post(route('portal.password.link'))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_the_return_page_shows_the_server_state_of_the_customers_own_attempt(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->create();

        $response = $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $attempt->order_id, 'transaction_status' => 'settlement', 'status_code' => '200']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Payments/Return')
            ->where('attempt.status', 'pending')
            ->where('attempt.amount', '154600.00')
            ->where('attempt.invoice.number', $invoice->number));

        $json = json_encode($response->viewData('page')['props']);
        foreach ([$attempt->snap_token, 'redirect_url', 'snap_token', $attempt->order_id] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_return_page_never_shows_another_customers_attempt(): void
    {
        [, $other] = $this->customerLogin($this->company);
        $theirs = PaymentAttempt::factory()->forInvoice($this->invoiceOf($other))->create();

        $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $theirs->order_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attempt', null));
        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))
            ->assertInertia(fn (Assert $page) => $page->where('attempt', null));
    }

    public function test_the_return_page_without_an_order_id_shows_the_latest_own_attempt(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        PaymentAttempt::factory()->forInvoice($invoice)->status('expired')->create();
        $latest = PaymentAttempt::factory()->forInvoice($invoice)->status('paid')->create();

        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))
            ->assertInertia(fn (Assert $page) => $page->where('attempt.status', $latest->status));
    }

    public function test_an_exception_attempt_does_not_leak_its_internal_reason_to_the_customer(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->status('exception')->create(['status_reason' => 'gross_amount "1.00" differs from the attempt amount']);

        $response = $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $attempt->order_id]));

        $this->assertStringNotContainsString('gross_amount', json_encode($response->viewData('page')['props']));
    }
}
