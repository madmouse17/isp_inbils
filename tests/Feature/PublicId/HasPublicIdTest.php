<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HasPublicIdTest extends TestCase
{
    use RefreshDatabase;

    private const ULID = '/^[0-7][0-9a-hjkmnp-tv-z]{25}$/';

    private function customer(?Company $company = null): Customer
    {
        return Customer::factory()->create(['company_id' => ($company ?? Company::factory()->create())->id]);
    }

    public function test_a_new_record_gets_a_lowercase_ulid_and_keeps_its_integer_primary_key(): void
    {
        $customer = $this->customer();

        $this->assertMatchesRegularExpression(self::ULID, $customer->public_id);
        $this->assertNotSame($customer->public_id, $this->customer()->public_id);
        $this->assertSame('id', $customer->getKeyName());
        $this->assertSame('int', $customer->getKeyType());
        $this->assertTrue($customer->getIncrementing());
        $this->assertIsInt($customer->getKey());
    }

    public function test_the_route_key_is_the_public_id_and_urls_carry_it(): void
    {
        $customer = $this->customer();

        $this->assertSame('public_id', $customer->getRouteKeyName());
        $this->assertSame($customer->public_id, $customer->getRouteKey());
        $this->assertStringEndsWith('/admin/customers/'.$customer->public_id, route('admin.customers.show', $customer, false));
    }

    public function test_a_route_value_that_is_not_a_ulid_is_not_found_without_asking_the_database(): void
    {
        $customer = $this->customer();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertThrows(fn () => (new Customer())->resolveRouteBinding((string) $customer->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => (new Customer())->resolveRouteBinding('not-a-ulid'), ModelNotFoundException::class);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_public_id_resolves_inside_its_own_company_only(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $customer = $this->customer($own);

        CompanyService::runAs((int) $own->id, fn () => $this->assertTrue((new Customer())->resolveRouteBinding($customer->public_id)?->is($customer)));
        CompanyService::runAs((int) $other->id, fn () => $this->assertNull((new Customer())->resolveRouteBinding($customer->public_id)));
    }

    public function test_company_binding_keeps_answering_for_top_level_tenants_only(): void
    {
        $root = Company::factory()->create();
        $child = Company::factory()->create();
        $child->forceFill(['parent_company_id' => $root->id])->save();

        $this->assertTrue((new Company())->resolveRouteBinding($root->public_id)?->is($root));
        $this->assertNull((new Company())->resolveRouteBinding($child->public_id), 'the id of a subsidiary is no tenant');
        $this->assertNull((new Company())->resolveRouteBinding((string) $root->id), 'the integer id names nothing any more');
        $this->assertTrue((new Company())->resolveRouteBinding($child->code, 'code')?->is($child), 'a binding by another key still reaches subsidiaries');
    }

    public function test_a_replica_does_not_inherit_the_public_id(): void
    {
        $customer = $this->customer();
        $replica = $customer->replicate();

        $this->assertNull($replica->public_id);

        $replica->code = $customer->code.'-copy';
        $replica->save();

        $this->assertMatchesRegularExpression(self::ULID, $replica->public_id);
        $this->assertNotSame($customer->public_id, $replica->public_id);
    }

    public function test_package_models_get_a_public_id_when_they_are_created(): void
    {
        Storage::fake('public');

        $role = Role::create(['name' => 'public-id-probe', 'guard_name' => 'web']);
        $activity = activity()->log('public id probe');
        $token = User::factory()->create()->createToken('public-id-probe')->accessToken;
        $media = Company::factory()->create()->addMediaFromString('x')->usingFileName('logo.png')->toMediaCollection('logo');

        foreach (['role' => $role, 'activity' => $activity, 'token' => $token, 'media' => $media] as $what => $row) {
            $this->assertMatchesRegularExpression(self::ULID, (string) $row->getAttribute('public_id'), $what);
        }
    }
}
