<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_store_uses_number_sequence_and_created_by_is_nullable(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $customer = Customer::factory()->create();

        $response = $this->post(route('admin.invoices.store'), [
            'customer_id' => $customer->id,
        ]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $invoice->number);

        // created_by nullable at DB level (scheduler path)
        $bare = InvoiceFactory::new()->create(['created_by' => null]);
        $this->assertNull($bare->fresh()->created_by);
    }
}
