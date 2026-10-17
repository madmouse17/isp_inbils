<?php

namespace Tests\Feature\Support;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\SearchOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\OptionalProp;
use Tests\TestCase;

class SearchOptionsTest extends TestCase
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

    public function test_the_prop_is_optional_so_a_normal_page_load_never_runs_the_query(): void
    {
        $this->assertInstanceOf(OptionalProp::class, $this->customerOptions(null));
    }

    public function test_a_term_shorter_than_two_characters_returns_nothing(): void
    {
        $this->customer('Budi Santoso');

        $this->assertSame([], $this->resolve($this->customerOptions('b')));
        $this->assertSame([], $this->resolve($this->customerOptions('')));
        $this->assertSame([], $this->resolve($this->customerOptions(null)));
        $this->assertSame([], $this->resolve($this->customerOptions('  b ')));
    }

    public function test_options_carry_value_label_and_description_and_match_any_search_column(): void
    {
        $budi = $this->customer('Budi Santoso', 'CUS-BUDI-1');

        $this->assertSame(
            [['value' => $budi->public_id, 'label' => 'Budi Santoso', 'description' => 'CUS-BUDI-1']],
            $this->resolve($this->customerOptions('bud')),
        );
        $this->assertSame(
            [['value' => $budi->public_id, 'label' => 'Budi Santoso', 'description' => 'CUS-BUDI-1']],
            $this->resolve($this->customerOptions('BUDI-1')),
        );
    }

    public function test_results_are_limited_to_twenty_ordered_by_the_first_column(): void
    {
        foreach (range(1, 25) as $n) {
            $this->customer(sprintf('Pelanggan %02d', $n));
        }

        $labels = array_column($this->resolve($this->customerOptions('Pelanggan')), 'label');

        $this->assertCount(20, $labels);
        $this->assertSame('Pelanggan 01', $labels[0]);
        $this->assertSame('Pelanggan 20', $labels[19]);
    }

    public function test_like_wildcards_in_the_term_match_literally(): void
    {
        $this->customer('Diskon 50% Hemat');
        $this->customer('Diskon 50x Hemat');
        $this->customer('Toko_Satu');
        $this->customer('Toko Satu');

        $this->assertSame(['Diskon 50% Hemat'], array_column($this->resolve($this->customerOptions('50%')), 'label'));
        $this->assertSame(['Toko_Satu'], array_column($this->resolve($this->customerOptions('o_S')), 'label'));
        $this->assertSame([], $this->resolve($this->customerOptions('%%')));
    }

    public function test_another_companys_rows_never_appear(): void
    {
        $mine = $this->customer('Siti Aminah');
        $this->customer('Siti Foreign', company: $this->other);

        $this->assertSame([$mine->public_id], array_column($this->resolve($this->customerOptions('Siti')), 'value'));
    }

    public function test_the_selected_value_comes_first_and_is_not_repeated(): void
    {
        $selected = $this->customer('Zaki Pilihan');
        $match = $this->customer('Andi Cocok');
        $this->customer('Andi Lain', company: $this->other);

        $this->assertSame([$selected->public_id], array_column($this->resolve($this->customerOptions(null, $selected->public_id)), 'value'));
        $this->assertSame(
            [$selected->public_id, $match->public_id],
            array_column($this->resolve($this->customerOptions('Andi', $selected->public_id)), 'value'),
        );
        $this->assertSame(
            [$selected->public_id],
            array_column($this->resolve($this->customerOptions('Zaki', $selected->public_id)), 'value'),
        );
    }

    public function test_a_selected_value_outside_the_scoped_query_is_not_revealed(): void
    {
        $foreign = $this->customer('Siti Foreign', company: $this->other);

        $this->assertSame([], $this->resolve($this->customerOptions(null, $foreign->public_id)));
    }

    public function test_an_integer_is_not_a_selected_value(): void
    {
        $customer = $this->customer('Zaki Pilihan');

        $this->assertSame([], $this->resolve($this->customerOptions(null, (string) $customer->id)));
    }

    public function test_a_user_of_another_company_never_appears_in_the_options(): void
    {
        $mine = User::factory()->create(['name' => 'Rina Teknisi', 'company_id' => $this->company->id]);
        $theirs = User::factory()->create(['name' => 'Rina Asing', 'company_id' => $this->other->id]);
        $this->member($this->company, $mine);
        $this->member($this->other, $theirs);

        $base = User::query()->whereIn(
            'users.id',
            DB::table('company_user')->where('company_id', CompanyService::currentId())->select('user_id'),
        );

        $options = SearchOptions::for(
            $this->searchRequest('handler', 'Rina'),
            'handler',
            $base,
            ['users.name', 'users.email'],
            fn (User $user): array => ['value' => $user->public_id, 'label' => $user->name],
            $theirs->public_id,
        );

        $this->assertSame([['value' => $mine->public_id, 'label' => 'Rina Teknisi', 'description' => null]], $this->resolve($options));
    }

    private function customerOptions(?string $term, ?string $selected = null): OptionalProp
    {
        return SearchOptions::for(
            $this->searchRequest('customer', $term),
            'customer',
            Customer::query(),
            ['name', 'code'],
            fn (Customer $customer): array => ['value' => $customer->public_id, 'label' => $customer->name, 'description' => $customer->code],
            $selected,
        );
    }

    private function searchRequest(string $field, ?string $term): Request
    {
        return Request::create('/', 'GET', $term === null ? [] : [$field.'_search' => $term]);
    }

    /** @return list<array{value: string, label: string, description: string|null}> */
    private function resolve(OptionalProp $prop): array
    {
        return $prop();
    }

    private function customer(string $name, ?string $code = null, ?Company $company = null): Customer
    {
        return Customer::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'name' => $name,
            'code' => $code ?? 'CUS-'.fake()->unique()->numerify('########'),
        ]);
    }

    private function member(Company $company, User $user): void
    {
        DB::table('company_user')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
