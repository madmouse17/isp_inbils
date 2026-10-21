<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\NumberSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-U02: a manual invoice is made in one form. The draft and its lines are one write, every line takes the company's
 * PPN (never a typed one), and the totals are whole rupiah (K1).
 */
class InvoiceItemsFormTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $company = $this->admin->company;
        $company->update(['settings' => [...($company->settings ?? []), 'tax_ppn_rate' => 11]]);
        $this->customer = Customer::factory()->create(['company_id' => $company->id]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $items, array $overrides = []): array
    {
        return ['customer_id' => $this->customer->public_id, 'due_date' => now()->addDays(14)->toDateString(), 'items' => $items, ...$overrides];
    }

    public function test_the_draft_and_its_lines_are_one_write_with_the_company_tax_and_whole_rupiah_totals(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.store'), $this->body([
                ['description' => 'Kabel fiber 2 m', 'quantity' => 2, 'unit_price' => 150000],
                ['description' => 'Konektor', 'quantity' => 1.5, 'unit_price' => 3333],
            ]))
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success', 'Tagihan dibuat.');

        $invoice = Invoice::query()->with('items')->sole();

        $this->assertSame(['draft', 'manual', 'one_time'], [$invoice->status, $invoice->source, $invoice->type]);
        $this->assertSame(['Kabel fiber 2 m', 'Konektor'], $invoice->items->pluck('description')->all());
        $this->assertSame(['300000.00', '5000.00'], $invoice->items->pluck('line_total')->all(), '1,5 x 3.333 = 4.999,5 rounds half up to 5.000');
        $this->assertSame(['11.00', '11.00'], $invoice->items->pluck('tax_rate')->all());
        $this->assertSame([$this->admin->company_id, $this->admin->company_id], $invoice->items->pluck('company_id')->all());
        $this->assertSame('305000.00', $invoice->subtotal);
        $this->assertSame('33550.00', $invoice->tax_amount, 'tax is rounded line by line: 33.000 + 550');
        $this->assertSame('338550.00', $invoice->total);
        $this->assertSame($this->admin->id, $invoice->created_by);
    }

    public function test_the_tax_is_the_companys_even_when_the_request_names_another(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.store'), $this->body([['description' => 'Jasa', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 0, 'discount_amount' => 99999, 'line_total' => 1]]))
            ->assertSessionHasNoErrors();

        $item = Invoice::query()->sole()->items->sole();

        $this->assertSame('11.00', $item->tax_rate);
        $this->assertSame('0.00', $item->discount_amount);
        $this->assertSame('100000.00', $item->line_total);
    }

    public function test_a_company_without_its_own_rate_uses_the_system_default(): void
    {
        $company = $this->admin->company;
        $company->update(['settings' => collect($company->settings)->except('tax_ppn_rate')->all()]);

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.store'), $this->body([['description' => 'Jasa', 'quantity' => 1, 'unit_price' => 100000]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('11.00', Invoice::query()->sole()->items->sole()->tax_rate);
    }

    public function test_a_line_added_to_a_draft_takes_the_company_tax_whatever_the_dialog_sent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), $this->body([]))->assertSessionHasNoErrors();
        $draft = Invoice::query()->sole();

        $this->post(route('admin.invoices.items.store', $draft), ['description' => 'Kabel', 'quantity' => 2, 'unit_price' => 50000, 'tax_rate' => 0, 'discount_amount' => 10000])
            ->assertSessionHasNoErrors();

        $item = $draft->items()->sole();
        $this->assertSame('11.00', $item->tax_rate, 'the company PPN, not the 0 the request named');
        $this->assertSame('10000.00', $item->discount_amount, 'a discount on the line is still the dialog\'s own');
        $draft->refresh();
        $this->assertSame(['90000.00', '9900.00', '99900.00'], [$draft->subtotal, $draft->tax_amount, $draft->total]);
    }

    public function test_without_a_due_date_the_term_runs_from_the_issue_date_not_from_today(): void
    {
        $issued = now()->addDays(30)->toDateString();

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.store'), $this->body([], ['issue_date' => $issued, 'due_date' => null]))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::query()->sole();
        $this->assertSame($issued, $invoice->issue_date->toDateString());
        $this->assertSame(now()->addDays(30 + 14)->toDateString(), $invoice->due_date->toDateString(), 'the term of 14 days starts on the day the invoice is issued');
    }

    public function test_a_note_is_at_most_five_thousand_characters(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.invoices.store'), $this->body([], ['notes' => str_repeat('a', 5001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
        $this->assertSame(0, Invoice::query()->count());

        $this->postJson(route('admin.invoices.store'), $this->body([], ['notes' => str_repeat('a', 5000)]))->assertRedirect();
        $this->assertSame(5000, mb_strlen(Invoice::query()->sole()->notes));
    }

    public function test_a_draft_without_items_is_still_made_and_cannot_be_sent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invoices.store'), $this->body([], ['notes' => 'tanpa item']))->assertRedirect(route('admin.invoices.index'));
        $this->post(route('admin.invoices.store'), ['customer_id' => $this->customer->public_id, 'notes' => 'tanpa kunci item'])->assertRedirect(route('admin.invoices.index'));

        $invoices = Invoice::query()->withCount('items')->get();

        $this->assertCount(2, $invoices);
        $this->assertSame([0, 0], $invoices->pluck('items_count')->all());
        $this->assertSame(['0.00', '0.00'], $invoices->pluck('total')->all());

        $this->postJson(route('admin.invoices.send', $invoices->first()))->assertUnprocessable();
    }

    public function test_a_product_of_the_company_is_kept_and_another_companys_is_refused(): void
    {
        $own = Product::factory()->create(['company_id' => $this->admin->company_id]);
        $foreign = Product::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.invoices.store'), $this->body([['product_id' => $foreign->public_id, 'description' => 'Barang', 'quantity' => 1, 'unit_price' => 1000]]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.product_id' => ['Barang yang dipilih tidak ditemukan.']]);
        $this->assertSame(0, Invoice::query()->count());

        $this->postJson(route('admin.invoices.store'), $this->body([['product_id' => $own->public_id, 'description' => 'Barang', 'quantity' => 1, 'unit_price' => 1000]]))->assertRedirect();

        $this->assertSame($own->id, Invoice::query()->sole()->items->sole()->product_id);
    }

    /** @return iterable<string, array{0: list<array<string, mixed>>, 1: string, 2: string}> */
    public static function refusedItems(): iterable
    {
        $line = ['description' => 'Jasa', 'quantity' => 1, 'unit_price' => 1000];

        yield 'no description' => [[[...$line, 'description' => '']], 'items.0.description', 'Deskripsi item wajib diisi.'];
        yield 'no quantity' => [[[...$line, 'quantity' => '']], 'items.0.quantity', 'Kuantitas wajib diisi.'];
        yield 'quantity of zero' => [[[...$line, 'quantity' => 0]], 'items.0.quantity', 'Kuantitas minimal 0.01.'];
        yield 'quantity too big' => [[[...$line, 'quantity' => 100001]], 'items.0.quantity', 'Kuantitas paling banyak 100000.'];
        yield 'quantity with three decimals' => [[[...$line, 'quantity' => '1.234']], 'items.0.quantity', 'Kuantitas paling banyak dua angka di belakang koma.'];
        yield 'quantity in exponent form' => [[[...$line, 'quantity' => '1e3']], 'items.0.quantity', 'Kuantitas paling banyak dua angka di belakang koma.'];
        yield 'no price' => [[[...$line, 'unit_price' => '']], 'items.0.unit_price', 'Harga satuan wajib diisi.'];
        yield 'negative price' => [[[...$line, 'unit_price' => -1]], 'items.0.unit_price', 'Harga satuan tidak boleh negatif.'];
        yield 'price too big' => [[[...$line, 'unit_price' => '100000000001']], 'items.0.unit_price', 'Harga satuan terlalu besar.'];
        yield 'a line that cannot be stored' => [[[...$line, 'quantity' => 100000, 'unit_price' => 2000000]], 'items.0.unit_price', 'Jumlah baris terlalu besar.'];
        yield 'the second line is the wrong one' => [[$line, [...$line, 'description' => '']], 'items.1.description', 'Deskripsi item wajib diisi.'];
        yield 'twenty-one lines' => [array_fill(0, 21, $line), 'items', 'Satu tagihan paling banyak 20 item.'];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    #[DataProvider('refusedItems')]
    public function test_a_wrong_line_is_refused_with_its_place_and_nothing_is_saved(array $items, string $key, string $message): void
    {
        $next = NumberSequenceService::peek('invoice', $this->admin->company_id);

        $this->actingAs($this->admin)
            ->postJson(route('admin.invoices.store'), $this->body($items))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$key => $message]);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame($next, NumberSequenceService::peek('invoice', $this->admin->company_id), 'a refused form burns no number');
    }

    public function test_a_viewer_cannot_make_an_invoice_with_lines(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['app.web', 'billing.view']);

        $this->actingAs($viewer)
            ->post(route('admin.invoices.store'), $this->body([['description' => 'Jasa', 'quantity' => 1, 'unit_price' => 1000]]))
            ->assertForbidden();

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_page_carries_the_companys_tax_rate_and_payment_term(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.invoices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Create')
                ->where('tax_rate', fn ($rate): bool => (float) $rate === 11.0)
                ->where('due_days', 14));
    }

    public function test_the_item_dialog_of_a_draft_starts_from_the_companys_tax_rate(): void
    {
        $draft = Invoice::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $this->customer->id, 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tax_rate', fn ($rate): bool => (float) $rate === 11.0));
    }
}
