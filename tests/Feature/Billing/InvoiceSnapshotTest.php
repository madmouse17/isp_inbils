<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Modules\Billing\Events\InvoiceIssued;
use Modules\Billing\Events\PaymentRecorded;
use Modules\Billing\Listeners\SendInvoiceWhatsAppMessages;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Support\InvoiceParty;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * An invoice prints the customer and package as they were when it left draft (spec master-lifecycle D4): a rename
 * afterwards never reaches an issued invoice, a draft still shows the live values, and a deleted draft comes back.
 */
class InvoiceSnapshotTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const FROZEN = [
        'customer_name' => 'PT Maju',
        'customer_address' => 'Jl. Pasang 1, Bandung, 40111',
        'customer_tax_id' => '01.234.567.8-901.000',
        'package_name' => 'Paket 20 Mbps',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The WhatsApp side effects of issuing and paying are not under test here.
        Event::fake([InvoiceIssued::class, PaymentRecorded::class]);

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
    }

    /** A subscription whose customer, installation address and package carry the values an invoice prints. */
    private function subscriptionWithParty(?Customer $customer = null): ServiceSubscription
    {
        $company = $this->admin->company;
        $customer ??= Customer::factory()->create(['company_id' => $company->id]);
        $customer->update(['name' => 'PT Maju', 'tax_id' => '01.234.567.8-901.000']);

        $address = CustomerAddress::factory()->create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Pasang',
            'address' => 'Jl. Pasang 1', 'city' => 'Bandung', 'postal_code' => '40111',
        ]);

        return ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $company->id, 'name' => 'Paket 20 Mbps'])->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'activation_date' => '2025-01-01',
        ]);
    }

    /** What a customer rename, a move and a package rename change on the live records. */
    private function renameParty(ServiceSubscription $subscription): void
    {
        $subscription->customer->update(['name' => 'PT Berubah', 'tax_id' => '99.999.999.9-999.999']);
        $subscription->installationAddress->update(['address' => 'Jl. Pindah 9', 'city' => 'Jakarta', 'postal_code' => '10110']);
        $subscription->servicePackage->update(['name' => 'Paket 100 Mbps']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function draftFor(ServiceSubscription $subscription, array $attributes = []): Invoice
    {
        return $this->invoiceFor($subscription, 0, ['status' => 'draft', ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function issued(ServiceSubscription $subscription, array $attributes = []): Invoice
    {
        return BillingService::send($this->draftFor($subscription, $attributes));
    }

    /** @return array<string, mixed> */
    private function printed(Invoice $invoice): array
    {
        return [
            'customer_name' => $invoice->customer_name_snapshot,
            'customer_address' => $invoice->customer_address_snapshot,
            'customer_tax_id' => $invoice->customer_tax_id_snapshot,
            'package_name' => $invoice->package_name_snapshot,
        ];
    }

    /** The template with the data the two PDF controllers hand it. */
    private function renderInvoiceView(Invoice $invoice): string
    {
        return view('pdf.invoice', [
            'invoice' => $invoice->load(['subscription', 'items']),
            'company' => $this->admin->company,
            'bankInfo' => '',
            'terbilang' => 'nol rupiah',
            'party' => InvoiceParty::of($invoice),
        ])->render();
    }

    /** @return list<array<string, mixed>> */
    private function queriesDuring(Closure $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }

    /** @return list<string> invoice numbers the index lists for a ?status= value, sorted */
    private function listedNumbers(array $query = []): array
    {
        $props = $this->get(route('admin.invoices.index', $query))->assertOk()->viewData('page')['props'];

        return collect($props['invoices']['data'])->pluck('number')->sort()->values()->all();
    }

    public function test_send_freezes_party(): void
    {
        $subscription = $this->subscriptionWithParty();
        $draft = $this->draftFor($subscription);

        $queries = $this->queriesDuring(fn () => BillingService::send($draft));

        $updates = array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'update `invoices`'));
        $this->assertCount(1, $updates, 'the snapshot and the status are one write');

        $sent = Invoice::query()->findOrFail($draft->id);
        $this->assertSame('sent', $sent->status);
        $this->assertSame(self::FROZEN, $this->printed($sent));

        $this->renameParty($subscription);

        $this->assertSame(self::FROZEN, $this->printed(Invoice::query()->findOrFail($draft->id)));
    }

    public function test_cancelling_a_draft_freezes_party(): void
    {
        $subscription = $this->subscriptionWithParty();
        $draft = $this->draftFor($subscription);

        BillingService::cancel($draft, 'Salah buat');
        $this->renameParty($subscription);

        $cancelled = Invoice::query()->findOrFail($draft->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(self::FROZEN, $this->printed($cancelled), 'a draft that is cancelled has left draft too');
    }

    public function test_send_freezes_the_primary_address_of_an_invoice_without_a_subscription(): void
    {
        $company = $this->admin->company;
        $customer = Customer::factory()->create(['company_id' => $company->id, 'name' => 'Toko Sinar', 'tax_id' => null]);
        CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Lain', 'address' => 'Jl. Lain 3', 'city' => 'Cimahi', 'postal_code' => '40511']);
        CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'label' => 'Utama', 'address' => 'Jl. Utama 2', 'city' => 'Cimahi', 'postal_code' => '40511', 'is_primary' => true]);
        $draft = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'status' => 'draft', 'total' => 100000]);

        $sent = BillingService::send($draft);

        $this->assertSame([
            'customer_name' => 'Toko Sinar',
            'customer_address' => 'Jl. Utama 2, Cimahi, 40511',
            'customer_tax_id' => null,
            'package_name' => null,
        ], $this->printed($sent));
    }

    public function test_the_address_line_names_the_region_of_the_address(): void
    {
        DB::table('indonesia_provinces')->insertOrIgnore(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insertOrIgnore(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insertOrIgnore(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insertOrIgnore(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);

        $subscription = $this->subscriptionWithParty();
        $subscription->installationAddress->update([
            'address' => 'Jl. Pasang 2', 'city' => 'Jakarta', 'postal_code' => '12346',
            'province_code' => '31', 'city_code' => '3171', 'district_code' => '3171010', 'village_code' => '3171010001',
        ]);

        $this->assertSame(
            'Jl. Pasang 2, GANDARIA UTARA, KEBAYORAN BARU, KOTA JAKARTA SELATAN, DKI JAKARTA, 12346',
            $this->issued($subscription)->customer_address_snapshot,
        );
    }

    public function test_recurring_generation_freezes_party_without_extra_queries(): void
    {
        $tables = ['customers', 'customer_addresses', 'service_packages', 'indonesia_provinces', 'indonesia_cities', 'indonesia_districts', 'indonesia_villages'];
        $reads = fn (array $queries): array => collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => count(array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], ' from `'.$table.'`'))),
        ])->all();

        foreach (range(1, 2) as $ignored) {
            $this->subscriptionWithParty();
        }
        $twoSubscriptions = $reads($this->queriesDuring(fn () => $this->assertSame(2, BillingService::generateForPeriod('2025-06')['created'])));

        foreach (range(1, 2) as $ignored) {
            $this->subscriptionWithParty();
        }
        $fourSubscriptions = $reads($this->queriesDuring(fn () => $this->assertSame(4, BillingService::generateForPeriod('2025-07')['created'])));

        $this->assertSame($twoSubscriptions, $fourSubscriptions, 'the party is read once for the whole batch, not once per invoice');
        $this->assertSame(1, $fourSubscriptions['customer_addresses']);

        $invoices = Invoice::query()->where('type', 'recurring')->whereDate('billing_period_start', '2025-07-01')->with('items')->get();
        $this->assertCount(4, $invoices);
        foreach ($invoices as $invoice) {
            $this->assertSame(self::FROZEN, $this->printed($invoice));
            $this->assertSame('MRC Paket 20 Mbps 2025-07-01 s/d 2025-07-31', $invoice->items->sole()->description);
        }
    }

    public function test_freezing_an_invoice_whose_relations_are_loaded_reads_nothing(): void
    {
        $subscription = $this->subscriptionWithParty();
        $subscription->load(['customer', 'servicePackage', 'installationAddress.province', 'installationAddress.regionCity', 'installationAddress.district', 'installationAddress.village']);
        $invoice = (new Invoice(['company_id' => $subscription->company_id, 'customer_id' => $subscription->customer_id]))
            ->setRelation('customer', $subscription->customer)
            ->setRelation('subscription', $subscription);

        $queries = $this->queriesDuring(fn () => InvoiceParty::freeze($invoice));

        $this->assertSame([], $queries);
        $this->assertSame(self::FROZEN, $this->printed($invoice));
    }

    public function test_freeze_keeps_an_existing_snapshot(): void
    {
        $subscription = $this->subscriptionWithParty();
        $invoice = $this->issued($subscription);
        $this->renameParty($subscription);

        InvoiceParty::freeze($invoice);

        $this->assertSame(self::FROZEN, $this->printed($invoice));
        $this->assertSame(self::FROZEN, InvoiceParty::of($invoice));
    }

    public function test_pdf_template_prints_frozen_name_after_customer_rename(): void
    {
        $subscription = $this->subscriptionWithParty();
        $invoice = $this->issued($subscription);
        $this->renameParty($subscription);

        $html = $this->renderInvoiceView($invoice->fresh());

        foreach (['PT Maju', 'Jl. Pasang 1, Bandung, 40111', 'NPWP: 01.234.567.8-901.000', $subscription->code.' - Paket 20 Mbps'] as $printed) {
            $this->assertStringContainsString($printed, $html);
        }
        foreach (['PT Berubah', 'Jl. Pindah 9', '99.999.999.9-999.999', 'Paket 100 Mbps'] as $live) {
            $this->assertStringNotContainsString($live, $html);
        }
    }

    public function test_pdf_endpoints_hand_the_template_the_frozen_party(): void
    {
        [$customerUser, $customer] = $this->customerLogin($this->admin->company);
        $subscription = $this->subscriptionWithParty($customer);
        $invoice = $this->issued($subscription);
        BillingService::recordPayment($invoice, 100000, 'cash');
        $this->renameParty($subscription);

        $parties = [];
        View::composer('pdf.invoice', function ($view) use (&$parties): void {
            $parties[] = $view->getData()['party'];
        });

        $this->get(route('admin.invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->api('GET', "customer/invoices/{$invoice->public_id}/receipt", token: $this->issueToken($customerUser, 'customer'))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertSame(
            [self::FROZEN, [...self::FROZEN, 'customer_tax_id' => null]],
            $parties,
            'staff see the NPWP as printed; the receipt a customer downloads prints none',
        );
    }

    public function test_portal_api_returns_frozen_fields(): void
    {
        [$customerUser, $customer] = $this->customerLogin($this->admin->company);
        $subscription = $this->subscriptionWithParty($customer);
        $invoice = $this->issued($subscription);
        $token = $this->issueToken($customerUser, 'customer');
        $this->renameParty($subscription);

        $show = $this->api('GET', "customer/invoices/{$invoice->public_id}", token: $token)->assertOk();
        $list = $this->api('GET', 'customer/invoices', token: $token)->assertOk();

        foreach (Arr::except(self::FROZEN, 'customer_tax_id') as $field => $value) {
            $show->assertJsonPath('data.'.$field, $value);
            $list->assertJsonPath('data.0.'.$field, $value);
        }

        foreach ([$show, $list] as $response) {
            $this->assertStringNotContainsString('customer_tax_id', $response->getContent(), 'the tax id never goes to a customer app');
            $this->assertStringNotContainsString(self::FROZEN['customer_tax_id'], $response->getContent());
        }
    }

    public function test_draft_prints_live_values(): void
    {
        $subscription = $this->subscriptionWithParty();
        $draft = $this->draftFor($subscription);
        $this->renameParty($subscription);

        $html = $this->renderInvoiceView($draft->fresh());

        foreach (['PT Berubah', 'Jl. Pindah 9, Jakarta, 10110', 'NPWP: 99.999.999.9-999.999', $subscription->code.' - Paket 100 Mbps'] as $live) {
            $this->assertStringContainsString($live, $html);
        }
        $this->assertStringNotContainsString('PT Maju', $html);
        $this->assertSame([null, null, null, null], array_values($this->printed($draft->fresh())), 'a draft has no snapshot');
    }

    public function test_web_resource_exposes_the_snapshot_and_the_deleted_stamp(): void
    {
        $subscription = $this->subscriptionWithParty();
        $draft = $this->draftFor($subscription);
        $issued = $this->issued($subscription);
        $this->renameParty($subscription);

        $show = fn (Invoice $invoice): array => $this->get(route('admin.invoices.show', $invoice))->assertOk()->viewData('page')['props']['invoice']['data'];

        $frozen = $show($issued);
        $this->assertSame(
            ['PT Maju', 'Jl. Pasang 1, Bandung, 40111', '01.234.567.8-901.000', 'Paket 20 Mbps'],
            [$frozen['customer_name_snapshot'], $frozen['customer_address_snapshot'], $frozen['customer_tax_id_snapshot'], $frozen['package_name_snapshot']],
        );
        $this->assertNull($frozen['deleted_at']);

        $live = $show($draft);
        $this->assertSame([null, null, null, null], [$live['customer_name_snapshot'], $live['customer_address_snapshot'], $live['customer_tax_id_snapshot'], $live['package_name_snapshot']]);
        $this->assertSame('PT Berubah', $live['customer']['name']);
    }

    public function test_receivables_show_snapshot_name(): void
    {
        $subscription = $this->subscriptionWithParty();
        $this->issued($subscription, ['issue_date' => now()->subDays(20)->toDateString()]);
        $subscription->customer->update(['name' => 'PT Berubah']);
        $this->issued($subscription);
        $subscription->customer->update(['name' => 'PT Live']);

        $row = BillingService::receivables()[0];

        $this->assertSame('PT Berubah', $row['customer'], 'the newest open invoice names the customer as it was printed');
        $this->assertSame(2, $row['invoice_count']);
        $this->assertSame($subscription->customer->public_id, $row['customer_id']);

        $subscription->customer->delete();

        $row = BillingService::receivables()[0];
        $this->assertSame('PT Berubah', $row['customer']);
        $this->assertSame($subscription->customer->public_id, $row['customer_id'], 'a deleted customer keeps its row and its link');
    }

    public function test_receivables_name_an_invoice_without_snapshot_by_the_live_customer(): void
    {
        $subscription = $this->subscriptionWithParty();
        $this->invoiceFor($subscription, 5);

        $this->assertSame('PT Maju', BillingService::receivables()[0]['customer']);
    }

    public function test_status_filter_values(): void
    {
        $this->assertSame(['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'], Invoice::STATUSES);

        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $make = fn (string $number, string $status) => Invoice::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'number' => $number, 'status' => $status]);
        $make('INV-F-DRAFT', 'draft');
        $make('INV-F-PAID', 'paid');
        $make('INV-F-GONE', 'draft')->delete();

        $this->assertSame(['INV-F-DRAFT', 'INV-F-PAID'], $this->listedNumbers(), 'deleted rows are hidden by default');
        $this->assertSame(['INV-F-PAID'], $this->listedNumbers(['status' => 'paid']));
        $this->assertSame(['INV-F-GONE'], $this->listedNumbers(['status' => 'dihapus']));
        $this->assertSame(['INV-F-DRAFT', 'INV-F-GONE', 'INV-F-PAID'], $this->listedNumbers(['status' => 'semua']));
        $this->assertSame(['INV-F-DRAFT', 'INV-F-PAID'], $this->listedNumbers(['status' => 'unknown']), 'an unknown value is the default view');

        $page = $this->get(route('admin.invoices.index', ['status' => 'dihapus']))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($page['invoices']['data'][0]['deleted_at']);
        $this->assertSame('dihapus', $page['filters']['status']);
        $this->assertTrue($page['can']['delete']);
        $this->assertTrue($page['can']['update']);
    }

    public function test_trashed_draft_restore(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $draft = Invoice::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'number' => 'INV-RESTORE', 'status' => 'draft']);

        $this->delete(route('admin.invoices.destroy', $draft))->assertRedirect();
        $this->assertSoftDeleted($draft);
        $this->assertSame([], $this->listedNumbers());
        $this->assertSame(['INV-RESTORE'], $this->listedNumbers(['status' => 'dihapus']));

        $this->post(route('admin.invoices.restore', $draft->public_id))
            ->assertRedirect()
            ->assertSessionHas('success', 'Tagihan dipulihkan.');

        $this->assertNotSoftDeleted($draft);
        $this->assertSame(['INV-RESTORE'], $this->listedNumbers(['status' => 'draft']));
        $this->assertSame([], $this->listedNumbers(['status' => 'dihapus']));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_restore_needs_the_delete_permission_and_stays_inside_the_company(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $draft = Invoice::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'status' => 'draft']);
        $draft->delete();

        $other = Company::factory()->create(['is_active' => true]);
        $foreign = Invoice::factory()->create(['company_id' => $other->id, 'customer_id' => Customer::factory()->create(['company_id' => $other->id])->id, 'status' => 'draft']);
        $foreign->delete();

        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['billing.view', 'billing.update', 'app.web']);

        $this->actingAs($viewer)->post(route('admin.invoices.restore', $draft->public_id))->assertForbidden();
        $this->assertSoftDeleted($draft);

        $this->actingAs($this->admin)->post(route('admin.invoices.restore', $foreign->public_id))->assertNotFound();
        $this->assertSoftDeleted($foreign);
    }

    public function test_history_relations_keep_their_deleted_parents(): void
    {
        $relations = [
            Invoice::class => ['customer', 'subscription', 'workOrder'],
            InvoiceItem::class => ['invoice', 'product'],
            Payment::class => ['invoice'],
            PaymentAttempt::class => ['invoice'],
        ];

        foreach ($relations as $model => $names) {
            foreach ($names as $name) {
                $this->assertContains(
                    SoftDeletingScope::class,
                    (new $model())->{$name}()->getQuery()->removedScopes(),
                    $model.'::'.$name.' must still resolve a deleted parent',
                );
            }
        }

        $subscription = $this->subscriptionWithParty();
        $invoice = $this->issued($subscription);
        $subscription->customer->delete();
        $subscription->delete();

        $reloaded = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame('PT Maju', $reloaded->customer?->name);
        $this->assertSame($subscription->code, $reloaded->subscription?->code);
    }

    public function test_a_deleted_customer_is_not_messaged_about_its_invoice(): void
    {
        $subscription = $this->subscriptionWithParty();
        $invoice = $this->issued($subscription);
        $event = new InvoiceIssued((int) $invoice->company_id, $invoice->id);

        Notification::fake();
        (new SendInvoiceWhatsAppMessages())->invoiceIssued($event);
        Notification::assertSentTo($subscription->customer, InvoiceIssuedNotification::class);

        $subscription->customer->delete();

        Notification::fake();
        (new SendInvoiceWhatsAppMessages())->invoiceIssued($event);
        Notification::assertNothingSent();
    }
}
