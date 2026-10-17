<?php

namespace Tests\Feature\Rules;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Spec .scratch/opaque-ids D3: a FormRequest that uses ResolvesPublicIds receives the integer id of every record
 * its client named by public id, so the rules and the services behind it keep integer keys, and a value that names
 * no record of the company is refused instead of passing as an integer.
 */
class ResolvesPublicIdsTest extends TestCase
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

    public function test_a_public_id_becomes_the_integer_id_before_the_rules_run(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $validated = $this->validate(['customer_id' => $customer->public_id]);

        $this->assertSame($customer->id, $validated['customer_id']);
    }

    public function test_an_integer_id_is_refused_even_though_the_record_exists(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $this->assertSame(['customer_id'], $this->failedFields(['customer_id' => $customer->id]));
        $this->assertSame(['customer_id'], $this->failedFields(['customer_id' => (string) $customer->id]));
    }

    public function test_an_unknown_id_and_another_companys_record_are_refused(): void
    {
        $foreign = Customer::factory()->create(['company_id' => $this->other->id]);

        $this->assertSame(['customer_id'], $this->failedFields(['customer_id' => strtolower((string) Str::ulid())]));
        $this->assertSame(['customer_id'], $this->failedFields(['customer_id' => $foreign->public_id]));
    }

    public function test_every_entry_of_a_list_is_resolved_and_only_the_bad_one_fails(): void
    {
        $first = Location::factory()->create(['company_id' => $this->company->id]);
        $second = Location::factory()->create(['company_id' => $this->company->id]);

        $validated = $this->validate(['places' => [['location_id' => $first->public_id], ['location_id' => $second->public_id]]]);

        $this->assertSame([$first->id, $second->id], array_column($validated['places'], 'location_id'));
        $this->assertSame(
            ['places.1.location_id'],
            $this->failedFields(['places' => [['location_id' => $first->public_id], ['location_id' => $second->id]]]),
        );
    }

    public function test_a_polymorphic_field_takes_its_model_from_the_input(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->assertSame($customer->id, $this->validate(['kind' => 'customer', 'subject_id' => $customer->public_id])['subject_id']);
        $this->assertSame($location->id, $this->validate(['kind' => 'location', 'subject_id' => $location->public_id])['subject_id']);
        $this->assertSame(['subject_id'], $this->failedFields(['kind' => 'location', 'subject_id' => $customer->public_id]));
    }

    public function test_an_empty_optional_field_is_left_alone(): void
    {
        $validated = $this->validate(['customer_id' => null]);

        $this->assertNull($validated['customer_id']);
    }

    public function test_a_list_sent_where_one_id_belongs_is_refused(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        // `owner_id[]=5` flattens below the declared path, and an exists rule alone accepts a list of existing ids.
        $this->assertSame(['owner_id'], $this->failedFields(['owner_id' => [$customer->id]]));
        $this->assertSame(['owner_id'], $this->failedFields(['owner_id' => [$customer->public_id]]));
        $this->assertSame(['owner_id'], $this->failedFields(['owner_id' => ['a' => ['b' => $customer->id]]]));
    }

    public function test_a_list_sent_where_one_id_belongs_is_refused_inside_a_list_too(): void
    {
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->assertSame(['stops.1.location_id'], $this->failedFields(['stops' => [['location_id' => $location->public_id], ['location_id' => [$location->id]]]]));
        $this->assertSame([$location->id], array_column($this->validate(['stops' => [['location_id' => $location->public_id]]])['stops'], 'location_id'));
    }

    public function test_a_key_with_a_dot_in_its_name_is_not_taken_for_the_declared_field(): void
    {
        // `location_id.0` is a key of its own: the field itself is missing, so its `required` rule still fails it.
        $this->assertSame(['stops.0.location_id'], $this->failedFields(['stops' => [['location_id.0' => '5']]]));
    }

    /** @return array<string, mixed> */
    private function validate(array $input): array
    {
        return CompanyService::runAs((int) $this->company->id, fn (): array => $this->request($input)->validated());
    }

    /** @return list<string> */
    private function failedFields(array $input): array
    {
        try {
            $this->validate($input);
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    }

    private function request(array $input): FormRequest
    {
        $probe = new class() extends FormRequest
        {
            use ResolvesPublicIds;

            public function authorize(): bool
            {
                return true;
            }

            /** @return array<string, mixed> */
            public function rules(): array
            {
                return [
                    'customer_id' => ['nullable', 'integer'],
                    'owner_id' => ['nullable', Rule::exists('customers', 'id')],
                    'places' => ['sometimes', 'array'],
                    'places.*.location_id' => ['required', 'integer'],
                    'stops' => ['sometimes', 'array'],
                    'stops.*.location_id' => ['required', Rule::exists('locations', 'id')],
                    'kind' => ['sometimes', 'in:customer,location'],
                    'subject_id' => ['sometimes', 'integer'],
                ];
            }

            protected function publicIdFields(): array
            {
                return [
                    'customer_id' => Customer::class,
                    'owner_id' => Customer::class,
                    'places.*.location_id' => Location::class,
                    'stops.*.location_id' => Location::class,
                    'subject_id' => fn (array $input): ?string => ['customer' => Customer::class, 'location' => Location::class][$input['kind'] ?? ''] ?? null,
                ];
            }
        };

        $request = $probe::createFrom(Request::create('/probe', 'POST', $input));
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->validateResolved();

        return $request;
    }
}
