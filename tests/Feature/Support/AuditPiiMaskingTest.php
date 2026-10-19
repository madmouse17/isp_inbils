<?php

namespace Tests\Feature\Support;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Support\AuditProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * log-audit LA-AUDIT-CORE, D8 (owner decision 8, OD-2): the audit diff of a customer, a contact, an address, an employee
 * and the company's bank account says which field changed and never what it held.
 */
class AuditPiiMaskingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_contact_changes_are_logged_without_their_values(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'name' => 'Budi Santoso',
            'email' => 'budi@example.test',
            'phone' => '081234567890',
            'tax_id' => '12.345.678.9-012.000',
            'contact_person' => 'Siti Aminah',
            'notes' => 'Rumah dekat masjid',
        ]);

        $created = $this->logOf($customer, 'created');
        $this->assertSame('Budi Santoso', $created->properties['attributes']['name'], 'what is not personal stays');
        foreach (['email', 'phone', 'tax_id', 'contact_person', 'notes'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $created->properties['attributes'][$column], $column);
        }

        $customer->update(['name' => 'Budi S.', 'phone' => '089900001111', 'email' => 'baru@example.test']);

        $updated = $this->logOf($customer, 'updated');
        $this->assertSame('Budi S.', $updated->properties['attributes']['name']);
        $this->assertSame('Budi Santoso', $updated->properties['old']['name']);
        foreach (['phone', 'email'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $updated->properties['attributes'][$column], "new {$column}");
            $this->assertSame(AuditProperties::MASKED, $updated->properties['old'][$column], "old {$column}");
        }

        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Rina',
            'role' => 'Istri',
            'phone' => '081200000001',
            'email' => 'kontak@example.test',
            'notes' => 'jangan telepon malam',
        ]);
        $contact->update(['phone' => '081200000002']);

        $contactCreated = $this->logOf($contact, 'created');
        $this->assertSame(['Rina', 'Istri'], [$contactCreated->properties['attributes']['name'], $contactCreated->properties['attributes']['role']]);
        foreach (['phone', 'email', 'notes'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $contactCreated->properties['attributes'][$column], $column);
        }
        $contactUpdated = $this->logOf($contact, 'updated');
        $this->assertSame([AuditProperties::MASKED, AuditProperties::MASKED], [$contactUpdated->properties['attributes']['phone'], $contactUpdated->properties['old']['phone']]);

        // A delete logs everything the row held as `old`: the personal columns are hidden there too.
        $contact->delete();
        $contactDeleted = $this->logOf($contact, 'deleted');
        $this->assertSame(['Rina', AuditProperties::MASKED, AuditProperties::MASKED, AuditProperties::MASKED], array_map(fn (string $column): mixed => $contactDeleted->properties['old'][$column], ['name', 'phone', 'email', 'notes']));

        $this->assertValuesAbsent([$created, $updated, $contactCreated, $contactUpdated, $contactDeleted], [
            'budi@example.test', 'baru@example.test', '081234567890', '089900001111', '12.345.678.9-012.000', 'Siti Aminah',
            'Rumah dekat masjid', '081200000001', '081200000002', 'kontak@example.test', 'jangan telepon malam',
        ]);
    }

    public function test_address_and_employee_changes_hide_personal_values(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $address = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Rumah',
            'address' => 'Jl. Melati No. 5 RT 02',
            'city' => 'Bandung',
            'lat' => -6.9147444,
            'lng' => 107.6098111,
            'notes' => 'pagar hijau, anjing galak',
        ]);
        $address->update(['address' => 'Jl. Mawar No. 9', 'lat' => -6.2, 'lng' => 106.8]);

        $addressCreated = $this->logOf($address, 'created');
        $this->assertSame(['Rumah', 'Bandung'], [$addressCreated->properties['attributes']['label'], $addressCreated->properties['attributes']['city']]);
        foreach (['address', 'lat', 'lng', 'notes'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $addressCreated->properties['attributes'][$column], $column);
        }
        $addressUpdated = $this->logOf($address, 'updated');
        foreach (['address', 'lat', 'lng'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $addressUpdated->properties['attributes'][$column], "new {$column}");
            $this->assertSame(AuditProperties::MASKED, $addressUpdated->properties['old'][$column], "old {$column}");
        }

        $employee = EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create(['company_id' => $company->id])->id,
            'employee_number' => 'EMP-0042',
            'phone' => '081355550000',
            'notes' => 'alergi debu',
        ]);
        $employee->update(['phone' => '081355559999']);

        $employeeCreated = $this->logOf($employee, 'created');
        $this->assertSame('EMP-0042', $employeeCreated->properties['attributes']['employee_number']);
        foreach (['phone', 'notes'] as $column) {
            $this->assertSame(AuditProperties::MASKED, $employeeCreated->properties['attributes'][$column], $column);
        }
        $employeeUpdated = $this->logOf($employee, 'updated');
        $this->assertSame([AuditProperties::MASKED, AuditProperties::MASKED], [$employeeUpdated->properties['attributes']['phone'], $employeeUpdated->properties['old']['phone']]);

        $this->assertValuesAbsent([$addressCreated, $addressUpdated, $employeeCreated, $employeeUpdated], [
            'Jl. Melati', 'Jl. Mawar', '-6.9147444', '107.6098111', '-6.2', '106.8', 'pagar hijau', '081355550000', '081355559999', 'alergi debu',
        ]);
    }

    public function test_company_settings_diff_hides_bank_account_info(): void
    {
        $company = Company::factory()->create(['settings' => ['bank_account_info' => 'BCA 028 123 4567 a.n. Inbils', 'invoice_due_days' => 14]]);

        $created = $this->logOf($company, 'created');
        $this->assertSame(AuditProperties::MASKED, $created->properties['attributes']['settings']['bank_account_info']);
        $this->assertSame(14, $created->properties['attributes']['settings']['invoice_due_days'], 'the rest of the settings stays readable');

        $company->update(['settings' => ['bank_account_info' => 'Mandiri 130 00 9876543', 'invoice_due_days' => 30]]);

        $updated = $this->logOf($company, 'updated');
        $this->assertSame(AuditProperties::MASKED, $updated->properties['attributes']['settings']['bank_account_info']);
        $this->assertSame(AuditProperties::MASKED, $updated->properties['old']['settings']['bank_account_info']);
        $this->assertSame([30, 14], [$updated->properties['attributes']['settings']['invoice_due_days'], $updated->properties['old']['settings']['invoice_due_days']]);
        $this->assertValuesAbsent([$created, $updated], ['BCA 028', 'Mandiri 130']);
    }

    public function test_a_change_to_a_masked_column_alone_still_writes_a_row(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id, 'phone' => null]);
        $before = Activity::query()->where('subject_type', Customer::class)->where('subject_id', $customer->id)->count();

        $customer->update(['phone' => '081234567890']);
        $customer->update(['phone' => '089900001111']);

        $this->assertSame($before + 2, Activity::query()->where('subject_type', Customer::class)->where('subject_id', $customer->id)->count());
        $rows = Activity::query()->where('subject_type', Customer::class)->where('subject_id', $customer->id)->where('description', 'updated')->orderBy('id')->get();
        $this->assertSame(['phone'], array_keys($rows[0]->properties['attributes']));
        $this->assertSame([AuditProperties::MASKED, null], [$rows[0]->properties['attributes']['phone'], $rows[0]->properties['old']['phone']], 'a first value shows as added, not as hidden');
        $this->assertSame([AuditProperties::MASKED, AuditProperties::MASKED], [$rows[1]->properties['attributes']['phone'], $rows[1]->properties['old']['phone']]);
        $this->assertValuesAbsent($rows->all(), ['081234567890', '089900001111']);
    }

    public function test_user_diffs_hide_every_email_and_a_customer_name_but_keep_staff_names(): void
    {
        $company = Company::factory()->create();
        $staff = User::factory()->create(['company_id' => $company->id, 'name' => 'Rina Teknisi', 'email' => 'rina@example.test']);

        $created = $this->logOf($staff, 'created');
        $this->assertSame(['Rina Teknisi', AuditProperties::MASKED], [$created->properties['attributes']['name'], $created->properties['attributes']['email']]);

        $staff->update(['name' => 'Rina S.', 'email' => 'rina.baru@example.test']);
        $updated = $this->logOf($staff, 'updated');
        $this->assertSame(['Rina S.', AuditProperties::MASKED], [$updated->properties['attributes']['name'], $updated->properties['attributes']['email']]);
        $this->assertSame(['Rina Teknisi', AuditProperties::MASKED], [$updated->properties['old']['name'], $updated->properties['old']['email']]);

        // The portal account of a customer: the role comes after the first save, so the created row relies on the flag.
        $portal = User::factory()->make(['company_id' => $company->id, 'name' => 'Siti Aminah', 'email' => 'siti@example.test']);
        $portal->isCustomerAccount = true;
        $portal->save();
        Role::findOrCreate('customer', 'web');
        $portal->assignRole('customer');

        $portalCreated = $this->logOf($portal, 'created');
        $this->assertSame([AuditProperties::MASKED, AuditProperties::MASKED], [$portalCreated->properties['attributes']['name'], $portalCreated->properties['attributes']['email']]);

        $portal = User::query()->findOrFail($portal->id);
        $portal->update(['name' => 'Siti A.', 'email' => 'siti.baru@example.test']);
        $portal->delete();

        $rows = [$portalCreated, $this->logOf($portal, 'updated'), $this->logOf($portal, 'deleted')];
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame(AuditProperties::MASKED, $row->properties[$row->description === 'updated' ? 'attributes' : 'old']['name'], $row->description);
        }
        $this->assertValuesAbsent([...$rows, $created, $updated], ['Siti', 'siti', 'rina@example.test', 'rina.baru@example.test']);
    }

    private function logOf(Model $subject, string $description): Activity
    {
        return Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('description', $description)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  list<Activity>  $rows
     * @param  list<string>  $values
     */
    private function assertValuesAbsent(array $rows, array $values): void
    {
        foreach ($rows as $row) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($value, (string) $row->getRawOriginal('properties'), "{$row->description} of {$row->subject_type}");
            }
        }
    }
}
