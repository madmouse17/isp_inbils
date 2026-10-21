<?php

namespace Tests\Feature\Roles\Security;

use App\Http\Middleware\EnforceImpersonation;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;

/**
 * A support operator who signs in as a tenant user (ImpersonationService) writes roles as that user.
 *
 * L3: he cannot change the roles of the account he is signed in as, like every other change to that account's own login.
 *
 * L2 was reported as "a tenant role row names the user instead of the operator, because CompanyRoleService passes the
 * actor as the causer". It does not reproduce: the package's CauserResolver returns the override the middleware sets
 * (setCauser) before any explicit causer, so the row already names the operator. The first test pins that, so a change of
 * either side (the middleware, the package, the service) shows here.
 */
class SupportSessionRoleWritesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 21, 9, 0, 0, 'UTC'));
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
    }

    private function startSupportSession(): void
    {
        $this->asOperator()->post(route('platform.impersonation.start', [$this->alpha['root'], $this->alpha['admin']]), [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'));

        $this->asOperator()->post(route('platform.impersonation.write'), [
            'confirmation_code' => $this->alpha['root']->code,
        ])->assertStatus(303)->assertSessionHas(ImpersonationService::MODE, ImpersonationService::WRITE);
    }

    public function test_a_role_written_during_a_support_session_is_attributed_to_the_operator(): void
    {
        $this->startSupportSession();
        Activity::query()->where('description', 'like', 'role.%')->delete();

        $this->asTenantVisitor()->post(route('admin.roles.store'), ['name' => 'Kasir', 'permissions' => ['billing.view']])
            ->assertRedirect(route('admin.roles.index'));

        $row = Activity::query()->where('description', 'role.created')->sole();
        $this->assertSame([PlatformAdmin::class, $this->operator->id], [$row->causer_type, (int) $row->causer_id], 'the operator, not the tenant admin he is signed in as');
        $this->assertSame([Role::class, (int) $this->alpha['root']->id], [$row->subject_type, (int) $row->company_id]);

        // Roles given to a user are attributed the same way (their rows never named the user).
        $this->asTenantVisitor()->put(route('admin.users.roles.update', $this->alpha['staff']), ['roles' => ['Kasir']])->assertRedirect();
        $attached = Activity::query()->where('description', 'role.attached')->sole();
        $this->assertSame([PlatformAdmin::class, $this->operator->id], [$attached->causer_type, (int) $attached->causer_id]);
    }

    public function test_the_roles_of_the_account_he_is_signed_in_as_stay_closed_to_the_operator(): void
    {
        $this->startSupportSession();
        $roles = fn (): array => DB::table('model_has_roles')->where('model_id', $this->alpha['admin']->id)->orderBy('role_id')->pluck('role_id')->all();
        $before = $roles();
        $this->assertNotSame([], $before);

        $this->asTenantVisitor()->putJson(route('admin.users.roles.update', $this->alpha['admin']), ['roles' => []])
            ->assertForbidden()
            ->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);

        $this->assertSame($before, $roles());

        // The roles of the other members of the company stay open to him in write mode.
        $this->asTenantVisitor()->putJson(route('admin.users.roles.update', $this->alpha['staff']), ['roles' => ['Staf']])->assertRedirect();
    }
}
