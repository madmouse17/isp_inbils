<?php

namespace Tests\Feature\Rules;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Rules\BelongsToCompany;
use App\Rules\HoldsPermission;
use App\Rules\IsActiveTechnician;
use App\Rules\MatchesParentAttribute;
use App\Rules\NoSodConflict;
use App\Rules\NoStackedSodConflict;
use App\Rules\PolymorphicBelongsToCompany;
use App\Services\Core\CompanyService;
use App\Support\SodConflicts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Ticketing\Models\Ticket;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/** What a rejected value says: the shared rules speak Indonesian and name the field from lang/id/validation.php. */
class ValidationRuleMessagesTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->admin = $this->groupUser($this->head);
        $this->actingAs($this->admin);
    }

    /** @param  array<string, mixed>  $data */
    private function errors(array $data, string $field, ValidationRule $rule): array
    {
        return Validator::make($data, [$field => [$rule]])->errors()->get($field);
    }

    private function withoutCompanyContext(): void
    {
        auth()->forgetGuards();
        CompanyService::resetCache();
    }

    public function test_belongs_to_company_names_the_field_and_the_missing_company_context(): void
    {
        $foreign = Customer::factory()->create(['company_id' => $this->outsider->id]);
        $rule = new BelongsToCompany(Customer::class);

        $this->assertSame(['pelanggan yang dipilih tidak valid untuk perusahaan ini.'], $this->errors(['customer_id' => $foreign->id], 'customer_id', $rule));

        $this->withoutCompanyContext();
        $this->assertSame(['Konteks perusahaan diperlukan.'], $this->errors(['customer_id' => $foreign->id], 'customer_id', $rule));
    }

    public function test_polymorphic_rule_names_the_type_the_field_and_the_missing_company_context(): void
    {
        $foreign = Ticket::factory()->create(['company_id' => $this->outsider->id]);
        $rule = fn (): PolymorphicBelongsToCompany => new PolymorphicBelongsToCompany(['Ticket' => Ticket::class], 'reference_type');

        $this->assertSame(
            ['Jenis referensi yang dipilih tidak diizinkan.'],
            $this->errors(['reference_type' => 'User', 'reference_id' => 1], 'reference_id', $rule()),
        );
        $this->assertSame(
            ['referensi yang dipilih tidak valid untuk perusahaan ini.'],
            $this->errors(['reference_type' => 'Ticket', 'reference_id' => $foreign->id], 'reference_id', $rule()),
        );

        $this->withoutCompanyContext();
        $this->assertSame(
            ['Konteks perusahaan diperlukan.'],
            $this->errors(['reference_type' => 'Ticket', 'reference_id' => $foreign->id], 'reference_id', $rule()),
        );
    }

    public function test_assignment_rules_say_who_is_not_eligible(): void
    {
        $customer = $this->groupUser($this->head, 'customer');
        $technician = $this->groupUser($this->head, 'technician');
        $holds = fn (?int $company = null): HoldsPermission => new HoldsPermission(['ticket.start'], $company);
        $message = 'pengguna yang dipilih harus berupa pengguna aktif dengan hak akses yang sesuai.';

        $this->assertSame([$message], $this->errors(['user_id' => $customer->id], 'user_id', $holds()));
        $this->assertSame([], $this->errors(['user_id' => $technician->id], 'user_id', $holds()));
        // The company is the one named, not the one the person is signed in to: the technician is no member of Sub A.
        $this->assertSame([$message], $this->errors(['user_id' => $technician->id], 'user_id', $holds($this->subA->id)));
        $this->assertSame(
            ['Teknisi yang dipilih harus karyawan aktif yang bisa mengerjakan SPK.'],
            $this->errors(['technician_id' => $customer->id], 'technician_id', new IsActiveTechnician()),
        );

        $this->withoutCompanyContext();
        $this->assertSame(['Konteks perusahaan diperlukan.'], $this->errors(['user_id' => $technician->id], 'user_id', $holds()));
    }

    public function test_parent_rule_names_the_parent_field(): void
    {
        $parent = Location::factory()->create(['company_id' => $this->head->id]);
        $other = Location::factory()->create(['company_id' => $this->head->id]);
        $child = Location::factory()->create(['company_id' => $this->head->id, 'parent_id' => $parent->id]);

        $this->assertSame(
            ['lokasi yang dipilih tidak sesuai dengan induk yang dipilih.'],
            $this->errors(['location_id' => $child->id, 'parent_id' => $other->id], 'location_id', new MatchesParentAttribute('locations', 'parent_id', 'parent_id')),
        );
        $this->assertSame([], $this->errors(['location_id' => $child->id, 'parent_id' => $parent->id], 'location_id', new MatchesParentAttribute('locations', 'parent_id', 'parent_id')));
    }

    public function test_separation_of_duties_rules_pass_the_conflict_text_through_and_exempt_administrators(): void
    {
        $staff = $this->groupUser($this->head, 'staff');
        $conflict = ['inventory.request.approve', 'inventory.request.issue'];
        $expected = SodConflicts::violations($conflict);
        $this->assertCount(1, $expected);

        $this->assertSame($expected, $this->errors(['permissions' => $conflict], 'permissions', new NoSodConflict()));
        $this->assertSame([], $this->errors(['permissions' => $conflict], 'permissions', new NoSodConflict(exempt: true)));

        $this->companyRole($this->head, 'rule-approver', [$conflict[0]]);
        $this->companyRole($this->head, 'rule-issuer', [$conflict[1]]);
        $roles = ['rule-approver', 'rule-issuer'];

        $this->assertSame($expected, $this->errors(['roles' => $roles], 'roles', new NoStackedSodConflict($staff)));
        $this->assertSame([], $this->errors(['roles' => $roles], 'roles', new NoStackedSodConflict($this->admin)));
    }
}
