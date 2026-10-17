<?php

namespace Tests\Feature\Support;

use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;

/** The query string is the caller's: a search parameter that is not a string must be ignored, never turn into a 500. */
class SearchOptionsHttpTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function queries(): array
    {
        return [
            'list' => ['customer_search[]=a'],
            'list of two' => ['customer_search[]=ab&customer_search[]=cd'],
            'map' => ['customer_search[name]=abc'],
            'nested map' => ['customer_search[a][b]=abc'],
        ];
    }

    #[DataProvider('queries')]
    public function test_an_array_search_parameter_gets_200_and_no_options(string $query): void
    {
        $this->actingAs($this->createCompanyUser())
            ->get('/admin/invoices?'.$query, $this->partialReload('Admin/Billing/Invoices/Index', 'customerOptions'))
            ->assertOk()
            ->assertJsonPath('props.customerOptions', []);
    }

    public function test_a_string_search_parameter_still_finds_options(): void
    {
        $user = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $user->company_id, 'name' => 'Budi Santoso']);

        $this->actingAs($user)
            ->get('/admin/invoices?customer_search=Budi', $this->partialReload('Admin/Billing/Invoices/Index', 'customerOptions'))
            ->assertOk()
            ->assertJsonPath('props.customerOptions.0.value', $customer->public_id);
    }
}
