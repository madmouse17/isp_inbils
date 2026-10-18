<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Services\Core\NumberSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * A manual invoice takes its number inside the transaction that inserts it, so an insert that fails hands the number back.
 */
class InvoiceNumberGapTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_a_failed_manual_invoice_create_gives_its_number_back(): void
    {
        $user = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        // The notes differ per request: an identical body inside ten seconds would be refused as a double submit.
        $store = fn (string $notes) => $this->actingAs($user)->post(route('admin.invoices.store'), ['customer_id' => $customer->public_id, 'notes' => $notes]);

        $store('first')->assertRedirect(route('admin.invoices.index'));
        $next = NumberSequenceService::peek('invoice', $user->company_id);

        $failing = true;
        Invoice::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('the insert failed');
            }
        });
        $this->withoutExceptionHandling();

        $this->assertThrows(fn () => $store('second'), RuntimeException::class, 'the insert failed');
        $this->assertSame($next, NumberSequenceService::peek('invoice', $user->company_id), 'the number of the failed create was not burned');

        $failing = false;
        $store('third')->assertRedirect(route('admin.invoices.index'));

        $this->assertSame($next, Invoice::withoutCompany()->latest('id')->value('number'), 'the next invoice takes it: no gap');
        $this->assertSame(2, Invoice::withoutCompany()->count());
    }
}
