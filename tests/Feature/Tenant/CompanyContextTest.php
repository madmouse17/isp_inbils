<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Invoice;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

class CompanyContextTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    // ---------------------------------------------------------------------------------------------
    // Effective company resolution: session active company, validated against membership
    // ---------------------------------------------------------------------------------------------

    public function test_a_user_without_an_active_company_acts_in_their_home_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user);

        $this->assertSame($this->head->id, CompanyService::currentId());
        $this->assertSame($this->head->id, CompanyService::idFor($user));
    }

    public function test_the_session_active_company_wins_for_a_member(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user);
        session([CompanyService::SESSION_KEY => $this->subA->id]);

        $this->assertSame($this->subA->id, CompanyService::currentId());
        $this->assertSame($this->subA->id, CompanyService::idFor($user));
        $this->assertSame($this->subA->id, CompanyService::current()?->id);
    }

    public function test_a_session_company_the_user_is_not_a_member_of_is_ignored(): void
    {
        $user = $this->groupUser($this->head);

        $this->actingAs($user);
        session([CompanyService::SESSION_KEY => $this->subA->id]);

        $this->assertSame($this->head->id, CompanyService::currentId());
    }

    public function test_an_inactive_session_company_is_ignored_even_for_a_member(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->subA->forceFill(['is_active' => false])->save();

        $this->actingAs($user);
        session([CompanyService::SESSION_KEY => $this->subA->id]);

        $this->assertSame($this->head->id, CompanyService::currentId());
    }

    public function test_a_revoked_membership_falls_back_to_the_home_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user);
        session([CompanyService::SESSION_KEY => $this->subA->id]);
        $this->assertSame($this->subA->id, CompanyService::currentId());

        app(CompanyMembershipService::class)->revoke($user, $this->subA);

        $this->assertSame($this->head->id, CompanyService::currentId());
    }

    public function test_id_for_another_user_is_that_users_home_company_not_the_session_company(): void
    {
        $actor = $this->groupUser($this->head, 'admin', $this->subA);
        $other = $this->groupUser($this->subB, 'admin', $this->subA);

        $this->actingAs($actor);
        session([CompanyService::SESSION_KEY => $this->subA->id]);

        $this->assertSame($this->subA->id, CompanyService::idFor($actor));
        $this->assertSame($this->subB->id, CompanyService::idFor($other));
    }

    // ---------------------------------------------------------------------------------------------
    // Fail closed: no company context means no tenant rows and no tenant writes
    // ---------------------------------------------------------------------------------------------

    public function test_a_null_context_reads_no_tenant_rows_at_all(): void
    {
        $customerA = Customer::factory()->create(['company_id' => $this->subA->id]);
        Customer::factory()->create(['company_id' => $this->subB->id]);
        Invoice::factory()->create(['company_id' => $this->subA->id]);

        Auth::logout();
        CompanyService::resetCache();

        $this->assertNull(CompanyService::currentId());
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull(Customer::query()->find($customerA->id));
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_explicit_opt_outs_still_see_rows_without_a_context(): void
    {
        $customerA = Customer::factory()->create(['company_id' => $this->subA->id]);
        Customer::factory()->create(['company_id' => $this->subB->id]);

        Auth::logout();
        CompanyService::resetCache();

        $this->assertSame(2, Customer::withoutCompany()->count());
        $this->assertSame([$customerA->id], Customer::forCompany($this->subA->id)->pluck('id')->all());
    }

    public function test_creating_a_tenant_row_without_a_context_or_company_aborts_403(): void
    {
        Auth::logout();
        CompanyService::resetCache();

        try {
            Customer::query()->create(['name' => 'Orphan', 'type' => 'Individual', 'code' => 'ORPHAN-1']);
            $this->fail('A tenant row was created without any company context.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Customer::withoutCompany()->where('code', 'ORPHAN-1')->count());
    }

    public function test_run_as_gives_system_code_an_explicit_company_and_restores_the_previous_one(): void
    {
        Customer::factory()->create(['company_id' => $this->subA->id, 'code' => 'CUST-A']);
        Customer::factory()->create(['company_id' => $this->subB->id, 'code' => 'CUST-B']);

        Auth::logout();
        CompanyService::resetCache();

        $seen = CompanyService::runAs($this->subA->id, fn (): array => [
            CompanyService::currentId(),
            Customer::query()->pluck('code')->all(),
        ]);

        $this->assertSame([$this->subA->id, ['CUST-A']], $seen);
        $this->assertNull(CompanyService::currentId());
    }

    public function test_run_as_nests_and_restores_even_when_the_callback_throws(): void
    {
        $outer = $this->subA->id;
        $inner = $this->subB->id;

        $ids = CompanyService::runAs($outer, fn (): array => [
            CompanyService::currentId(),
            CompanyService::runAs($inner, fn (): ?int => CompanyService::currentId()),
            CompanyService::currentId(),
        ]);
        $this->assertSame([$outer, $inner, $outer], $ids);

        try {
            CompanyService::runAs($outer, function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('The callback exception was swallowed.');
        } catch (RuntimeException) {
            $this->assertNull(CompanyService::currentId());
        }
    }

    public function test_run_as_lets_system_code_create_rows_for_its_company(): void
    {
        $customer = CompanyService::runAs($this->subB->id, fn (): Customer => Customer::query()->create([
            'name' => 'System made',
            'type' => 'Individual',
            'code' => 'SYS-1',
        ]));

        $this->assertSame($this->subB->id, $customer->company_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Artisan paths keep working with an explicit company
    // ---------------------------------------------------------------------------------------------

    public function test_check_overdue_runs_for_every_active_company_or_the_named_one(): void
    {
        $invoiceA = $this->overdueInvoice($this->subA->id);
        $invoiceB = $this->overdueInvoice($this->subB->id);

        $this->artisan('billing:check-overdue', ['--company' => $this->subA->id])->assertExitCode(0);

        $this->assertSame('overdue', Invoice::withoutCompany()->find($invoiceA->id)->status);
        $this->assertSame('sent', Invoice::withoutCompany()->find($invoiceB->id)->status);

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        $this->assertSame('overdue', Invoice::withoutCompany()->find($invoiceB->id)->status);
    }

    private function overdueInvoice(int $companyId): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $companyId,
            'status' => 'sent',
            'issue_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'total' => 100000,
            'paid_amount' => 0,
        ]);
    }

    public function test_the_membership_service_never_revokes_the_home_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->head->id]);
        $service = app(CompanyMembershipService::class);
        $service->grant($user, $this->head, true);

        $this->expectException(HttpException::class);

        $service->revoke($user, $this->head);
    }
}
