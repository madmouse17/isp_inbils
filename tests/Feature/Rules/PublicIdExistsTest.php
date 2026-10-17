<?php

namespace Tests\Feature\Rules;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Rules\PublicIdExists;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec .scratch/opaque-ids D3: a reference field accepts the public id of a record the current company can see, nothing else. */
class PublicIdExistsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->other = Company::factory()->create();
    }

    public function test_the_public_id_of_a_record_of_the_current_company_passes(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $this->assertTrue($this->passes($customer->public_id, new PublicIdExists(Customer::class)));
    }

    public function test_an_integer_id_fails_in_every_spelling(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        foreach ([$customer->id, (string) $customer->id, (string) $customer->id.'abc', [$customer->id], true] as $value) {
            $this->assertFalse($this->passes($value, new PublicIdExists(Customer::class)), var_export($value, true));
        }
    }

    public function test_an_unknown_id_and_a_foreign_record_fail_with_the_same_message(): void
    {
        $foreign = Customer::factory()->create(['company_id' => $this->other->id]);

        $unknown = $this->errorOf(strtolower((string) Str::ulid()), new PublicIdExists(Customer::class));
        $theirs = $this->errorOf($foreign->public_id, new PublicIdExists(Customer::class));

        $this->assertNotNull($unknown);
        $this->assertSame($unknown, $theirs);
    }

    public function test_the_case_of_the_id_does_not_matter_for_a_ulid(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $this->assertTrue($this->passes(strtoupper($customer->public_id), new PublicIdExists(Customer::class)));
    }

    public function test_the_field_can_ask_for_more_than_existing(): void
    {
        $active = Customer::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $inactive = Customer::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
        $rule = new PublicIdExists(Customer::class, fn (Builder $query) => $query->where('is_active', true));

        $this->assertTrue($this->passes($active->public_id, $rule));
        $this->assertFalse($this->passes($inactive->public_id, $rule));
    }

    private function passes(mixed $value, PublicIdExists $rule): bool
    {
        return $this->errorOf($value, $rule) === null;
    }

    private function errorOf(mixed $value, PublicIdExists $rule): ?string
    {
        return CompanyService::runAs((int) $this->company->id, function () use ($value, $rule): ?string {
            $validator = Validator::make(['reference' => $value], ['reference' => [$rule]]);

            return $validator->fails() ? $validator->errors()->first('reference') : null;
        });
    }
}
