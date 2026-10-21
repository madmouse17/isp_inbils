<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/** The invoice pages name the customer and the subscription; the customer's record stays on the customer pages. */
class InvoicePayloadPrivacyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const PRIVATE = ['sari.pribadi@example.test', '99.888.777.6-543.210', 'Pelanggan lama, minta dihubungi sore hari.', 'Bapak Rahmat', '081234567890', 'Catatan langganan rahasia'];

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $companyId = (int) $this->admin->company_id;
        $customer = Customer::factory()->create([
            'company_id' => $companyId, 'name' => 'Sari Wulandari', 'phone' => self::PRIVATE[4], 'email' => self::PRIVATE[0],
            'tax_id' => self::PRIVATE[1], 'notes' => self::PRIVATE[2], 'contact_person' => self::PRIVATE[3],
        ]);
        $subscription = ServiceSubscription::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'notes' => self::PRIVATE[5]]);
        $this->invoice = Invoice::factory()->create([
            'company_id' => $companyId, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_index_and_show_embed_summaries_only(): void
    {
        $customer = Customer::query()->where('name', 'Sari Wulandari')->sole();
        $subscription = ServiceSubscription::query()->where('customer_id', Customer::query()->where('name', 'Sari Wulandari')->value('id'))->sole();

        foreach ([[route('admin.invoices.index'), 'invoices'], [route('admin.invoices.show', $this->invoice), 'invoice']] as [$url, $prop]) {
            $props = $this->actingAs($this->admin)->get($url)->assertOk()->viewData('page')['props'];
            $row = $props[$prop]['data'][0] ?? $props[$prop]['data'];

            $this->assertSame(['id' => $customer->public_id, 'code' => $customer->code, 'name' => 'Sari Wulandari'], $row['customer']);
            if ($prop === 'invoice') {
                $this->assertSame(['id' => $subscription->public_id, 'code' => $subscription->code], $row['subscription']);
            }

            $json = (string) json_encode($props[$prop]);
            foreach (self::PRIVATE as $private) {
                $this->assertStringNotContainsString($private, $json, $private);
            }
        }
    }

    public function test_the_list_leaves_out_the_tax_id_address_and_note_that_only_the_invoice_pages_print(): void
    {
        $customer = Customer::query()->where('name', 'Sari Wulandari')->sole();
        $sent = Invoice::factory()->create([
            'company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'created_by' => $this->admin->id,
            'status' => 'sent', 'sent_at' => now(), 'total' => 100000, 'notes' => 'Catatan internal: sering menunggak.',
            'customer_name_snapshot' => 'Sari Wulandari', 'customer_address_snapshot' => 'Jl. Pasang 1, Bandung, 40111', 'customer_tax_id_snapshot' => '01.234.567.8-901.000',
        ]);
        $frozen = ['Catatan internal: sering menunggak.', 'Jl. Pasang 1, Bandung, 40111', '01.234.567.8-901.000'];

        $rows = $this->actingAs($this->admin)->get(route('admin.invoices.index'))->assertOk()->viewData('page')['props']['invoices']['data'];
        $row = collect($rows)->firstWhere('id', $sent->public_id);

        $this->assertNotNull($row, 'the sent invoice is listed');
        foreach (['notes', 'customer_address_snapshot', 'customer_tax_id_snapshot'] as $key) {
            $this->assertArrayNotHasKey($key, $row, $key);
        }
        foreach ($frozen as $private) {
            $this->assertStringNotContainsString($private, (string) json_encode($rows), $private);
        }

        // The invoice's own page still prints them.
        $page = $this->get(route('admin.invoices.show', $sent))->assertOk()->viewData('page')['props']['invoice']['data'];
        $this->assertSame($frozen, [$page['notes'], $page['customer_address_snapshot'], $page['customer_tax_id_snapshot']]);
    }
}
