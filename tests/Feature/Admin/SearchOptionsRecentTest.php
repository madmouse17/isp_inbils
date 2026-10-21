<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use App\Support\SearchOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\OptionalProp;
use Tests\TestCase;

/** A picker opened before anything is typed gets the newest rows, and only when it asks for them. */
class SearchOptionsRecentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->other = Company::factory()->create(['is_active' => true]);
        CompanyService::useCompanyForTests($this->company->id);
    }

    public function test_an_empty_term_with_recent_returns_the_ten_newest_rows_newest_first(): void
    {
        $created = collect(range(1, 12))->map(fn (int $n): Customer => $this->customer(sprintf('Pelanggan %02d', $n)));

        $values = array_column($this->resolve(['customer_recent' => '1']), 'value');

        $this->assertSame($created->reverse()->take(10)->pluck('public_id')->values()->all(), $values);
    }

    public function test_without_recent_an_empty_term_still_returns_nothing(): void
    {
        $this->customer('Budi Santoso');

        $this->assertSame([], $this->resolve([]));
        $this->assertSame([], $this->resolve(['customer_search' => '']));
        $this->assertSame([], $this->resolve(['customer_recent' => '0']));
    }

    public function test_a_typed_term_wins_over_recent(): void
    {
        $this->customer('Andi Cocok');
        $this->customer('Zaki Terbaru');

        $labels = array_column($this->resolve(['customer_search' => 'Andi', 'customer_recent' => '1']), 'label');

        $this->assertSame(['Andi Cocok'], $labels);
    }

    public function test_another_companys_rows_never_appear_in_recent(): void
    {
        $mine = $this->customer('Siti Aminah');
        $this->customer('Siti Foreign', $this->other);

        $this->assertSame([$mine->public_id], array_column($this->resolve(['customer_recent' => '1']), 'value'));
    }

    public function test_the_selected_value_comes_first_and_is_not_repeated(): void
    {
        $oldest = $this->customer('Paling Lama');
        $newest = $this->customer('Paling Baru');

        $values = array_column($this->resolve(['customer_recent' => '1'], $oldest->public_id), 'value');

        $this->assertSame([$oldest->public_id, $newest->public_id], $values);
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array{value: string, label: string, description: string|null}>
     */
    private function resolve(array $query, ?string $selected = null): array
    {
        $prop = SearchOptions::for(
            Request::create('/', 'GET', $query),
            'customer',
            // A caller's own order is for the alphabetical search; recent must not follow it.
            Customer::query()->orderBy('name'),
            ['name', 'code'],
            fn (Customer $customer): array => ['value' => $customer->public_id, 'label' => $customer->name, 'description' => $customer->code],
            $selected,
        );
        $this->assertInstanceOf(OptionalProp::class, $prop);

        return $prop();
    }

    private function customer(string $name, ?Company $company = null): Customer
    {
        return Customer::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'name' => $name,
            'code' => 'CUS-'.fake()->unique()->numerify('########'),
        ]);
    }
}
