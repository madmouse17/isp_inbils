<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BelongsToCompanyTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tenant_test_models');
        Schema::create('tenant_test_models', function ($table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_global_scope_filters_by_current_company(): void
    {
        [$companyA, $companyB] = $this->companies();
        TenantTestModel::query()->create(['company_id' => $companyA->id, 'name' => 'A']);
        TenantTestModel::query()->create(['company_id' => $companyB->id, 'name' => 'B']);

        $this->actingAs(User::factory()->create(['company_id' => $companyA->id]));

        $this->assertSame(['A'], TenantTestModel::query()->pluck('name')->all());
    }

    public function test_company_id_auto_set_on_create(): void
    {
        [$company] = $this->companies();

        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $model = TenantTestModel::query()->create(['name' => 'A']);

        $this->assertSame($company->id, $model->company_id);
    }

    public function test_without_company_bypasses_scope(): void
    {
        [$companyA, $companyB] = $this->companies();
        TenantTestModel::query()->create(['company_id' => $companyA->id, 'name' => 'A']);
        TenantTestModel::query()->create(['company_id' => $companyB->id, 'name' => 'B']);

        $this->actingAs(User::factory()->create(['company_id' => $companyA->id]));

        $this->assertSame(['A', 'B'], TenantTestModel::withoutCompany()->orderBy('name')->pluck('name')->all());
    }

    public function test_for_company_bypasses_scope_only_for_explicit_company(): void
    {
        [$companyA, $companyB] = $this->companies();
        TenantTestModel::query()->create(['company_id' => $companyA->id, 'name' => 'A']);
        TenantTestModel::query()->create(['company_id' => $companyB->id, 'name' => 'B']);

        $this->actingAs(User::factory()->create(['company_id' => $companyA->id]));

        $this->assertSame(['B'], TenantTestModel::forCompany($companyB->id)->pluck('name')->all());
    }

    public function test_null_company_context_fails_closed(): void
    {
        [$companyA, $companyB] = $this->companies();
        TenantTestModel::query()->create(['company_id' => $companyA->id, 'name' => 'A']);
        TenantTestModel::query()->create(['company_id' => $companyB->id, 'name' => 'B']);

        $this->assertSame([], TenantTestModel::query()->pluck('name')->all());
        $this->assertSame(['A', 'B'], TenantTestModel::withoutCompany()->orderBy('name')->pluck('name')->all());
    }

    public function test_creating_without_a_company_id_or_context_aborts_403(): void
    {
        try {
            TenantTestModel::query()->create(['name' => 'Orphan']);
            $this->fail('A tenant row was created without any company context.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, TenantTestModel::withoutCompany()->count());
    }

    public function test_an_explicit_company_id_is_kept_without_a_context(): void
    {
        [$companyA] = $this->companies();

        $model = TenantTestModel::query()->create(['company_id' => $companyA->id, 'name' => 'A']);

        $this->assertSame($companyA->id, $model->company_id);
    }

    /**
     * @return array{Company, Company}
     */
    private function companies(): array
    {
        return [
            Company::query()->create(['name' => 'Tenant A', 'code' => 'TA']),
            Company::query()->create(['name' => 'Tenant B', 'code' => 'TB']),
        ];
    }
}

class TenantTestModel extends Model
{
    use BelongsToCompany;

    protected $table = 'tenant_test_models';

    protected $fillable = [
        'company_id',
        'name',
    ];
}
