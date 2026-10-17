<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

class InvoiceIndexPickerTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $user;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createCompanyUser();
        $this->other = Company::factory()->create(['is_active' => true]);
    }

    private function customer(string $name, string $code, ?Company $company = null): Customer
    {
        return Customer::factory()->create([
            'company_id' => ($company ?? $this->user->company)->id,
            'name' => $name,
            'code' => $code,
        ]);
    }

    public function test_index_sends_neither_the_unbounded_customer_list_nor_status_options(): void
    {
        $this->customer('Budi Santoso', 'CUS-BUDI-1');

        $this->actingAs($this->user)
            ->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Index')
                ->missing('customers')
                ->missing('statusOptions')
                ->missing('customerOptions')
                ->where('selectedCustomer', null)
            );
    }

    /** @return array<string, array{0: string}> */
    public static function realStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'sent' => ['sent'],
            'partial' => ['partial'],
            'paid' => ['paid'],
            'overdue' => ['overdue'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('realStatuses')]
    public function test_every_real_status_value_filters_the_list(string $status): void
    {
        $customer = $this->customer('Status Customer', 'CUS-STATUS-1');

        foreach (['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'] as $each) {
            Invoice::factory()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'number' => 'INV-FILTER-'.strtoupper($each),
                'status' => $each,
            ]);
        }

        $this->actingAs($this->user)
            ->get(route('admin.invoices.index', ['status' => $status]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.number', 'INV-FILTER-'.strtoupper($status))
                ->where('invoices.data.0.status', $status)
            );
    }

    public function test_customer_options_are_optional_and_company_scoped(): void
    {
        $mine = $this->customer('Alfa Pelanggan', 'CUS-ALFA-1');
        $this->customer('Alfa Asing', 'CUS-ALFA-2', $this->other);

        $this->actingAs($this->user)
            ->get(route('admin.invoices.index', ['customer_search' => 'Alfa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('customerOptions')
                ->reloadOnly('customerOptions', fn (Assert $reload) => $reload
                    ->has('customerOptions', 1)
                    ->where('customerOptions.0.value', $mine->public_id)
                    ->where('customerOptions.0.label', 'Alfa Pelanggan')
                    ->where('customerOptions.0.description', 'CUS-ALFA-1')
                )
            );
    }

    public function test_selected_customer_is_the_option_of_the_current_filter(): void
    {
        $mine = $this->customer('Zaki Pilihan', 'CUS-ZAKI-1');

        $this->actingAs($this->user)
            ->get(route('admin.invoices.index', ['customer_id' => $mine->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedCustomer.value', $mine->public_id)
                ->where('selectedCustomer.label', 'Zaki Pilihan')
                ->where('selectedCustomer.description', 'CUS-ZAKI-1')
                ->where('filters.customer_id', $mine->public_id)
            );
    }

    public function test_selected_customer_is_null_for_a_foreign_id(): void
    {
        $foreign = $this->customer('Siti Foreign', 'CUS-FOREIGN-1', $this->other);

        $this->actingAs($this->user)
            ->get(route('admin.invoices.index', ['customer_id' => $foreign->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedCustomer', null)
                ->reloadOnly('customerOptions', fn (Assert $reload) => $reload->has('customerOptions', 0))
            );
    }

    public function test_create_sends_company_scoped_subscription_options_and_no_unbounded_lists(): void
    {
        $mine = $this->subscriptionIn($this->user->company);
        $this->subscriptionIn($this->other);
        $this->subscriptionIn($this->user->company, 'terminated');

        $this->actingAs($this->user)
            ->get(route('admin.invoices.create', ['subscription_search' => $mine->code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Create')
                ->missing('customers')
                ->missing('subscriptions')
                ->missing('subscriptionOptions')
                ->reloadOnly('subscriptionOptions', fn (Assert $reload) => $reload
                    ->has('subscriptionOptions', 1)
                    ->where('subscriptionOptions.0.value', $mine->public_id)
                    ->where('subscriptionOptions.0.label', $mine->code)
                    ->where('subscriptionOptions.0.description', $mine->customer->name)
                )
            );
    }

    public function test_create_customer_options_hide_other_companies(): void
    {
        $mine = $this->customer('Beta Pelanggan', 'CUS-BETA-1');
        $this->customer('Beta Asing', 'CUS-BETA-2', $this->other);

        $this->actingAs($this->user)
            ->get(route('admin.invoices.create', ['customer_search' => 'Beta']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('customerOptions', fn (Assert $reload) => $reload
                    ->has('customerOptions', 1)
                    ->where('customerOptions.0.value', $mine->public_id)
                )
            );
    }
}
