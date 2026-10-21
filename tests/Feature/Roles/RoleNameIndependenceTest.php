<?php

namespace Tests\Feature\Roles;

use App\Exceptions\ApiException;
use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Reporting\Services\TechnicianReportAccess;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketFirstResponseDueNotification;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;

/**
 * A company names its roles as it likes and deletes the ones it does not use. Every business rule below must give the
 * same answer after each role of the company is renamed and the unused ones are gone: they decide by permission,
 * never by the name of a role.
 */
class RoleNameIndependenceTest extends TestCase
{
    use AsksInertiaForProps;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private ?TicketCategory $category = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->company = Company::factory()->create(['is_active' => true, 'timezone' => 'UTC']);
        $this->admin = $this->person('admin');
    }

    public function test_ticket_handlers_are_validated_by_permission(): void
    {
        $technician = $this->person('technician');
        $custom = $this->personWith(['ticket.resolve']);
        $viewer = $this->personWith(['ticket.view']);
        $customer = $this->person('customer');
        $inactive = $this->person('technician', ['is_active' => false]);
        $this->renameEveryRoleAndDeleteTheUnused();
        $this->actingAs($this->admin);

        foreach ([[$technician, true], [$custom, true], [$viewer, false], [$customer, false], [$inactive, false]] as [$handler, $accepted]) {
            $ticket = $this->openTicket();

            $response = $this->post(route('admin.tickets.assign', $ticket), [
                'handler_id' => $handler->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ]);

            if ($accepted) {
                $response->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame($handler->id, $ticket->fresh()->assigned_to);
            } else {
                $response->assertSessionHasErrors('handler_id');
                $this->assertSame('open', $ticket->fresh()->status);
            }
        }
    }

    public function test_evaluation_picker_and_store_follow_the_evaluable_permission(): void
    {
        $technician = $this->person('technician', ['name' => 'Karyawan Teknisi']);
        $staff = $this->person('staff', ['name' => 'Karyawan Staf']);
        $marked = $this->personWith(['evaluation.evaluable'], ['name' => 'Karyawan Bertanda']);
        $manager = $this->person('manager', ['name' => 'Karyawan Manajer']);
        $plain = $this->personWith(['ticket.view'], ['name' => 'Karyawan Biasa']);
        // Reads only their own evaluations: they hold evaluation.view.own, not evaluation.view.
        $ownOnly = $this->personWith(['app.web', 'evaluation.view.own', 'evaluation.evaluable'], ['name' => 'Karyawan Sendiri']);
        $this->renameEveryRoleAndDeleteTheUnused();
        $this->actingAs($this->admin);

        $labels = $this->get(route('admin.evaluations.index', ['employee_search' => 'Karyawan']), $this->partialReload('Admin/Evaluations/Index', 'employeeOptions'))
            ->assertOk()
            ->json('props.employeeOptions.*.label');

        $this->assertSame(['Karyawan Bertanda', 'Karyawan Sendiri', 'Karyawan Staf', 'Karyawan Teknisi'], $labels);

        // One evaluator evaluates a reference once (eval_ref_evaluator_unique): every employee gets a ticket of their own.
        foreach ([[$technician, true], [$staff, true], [$marked, true], [$ownOnly, true], [$manager, false], [$plain, false]] as [$employee, $accepted]) {
            $response = $this->post(route('admin.evaluations.store'), [
                'employee_id' => $employee->public_id,
                'reference_type' => 'Ticket',
                'reference_id' => $this->openTicket()->public_id,
                'score' => 4.5,
            ]);

            $accepted
                ? $response->assertSessionHasNoErrors()->assertRedirect(route('admin.evaluations.index'))
                : $response->assertSessionHasErrors(['employee_id' => 'karyawan yang dipilih harus berupa pengguna aktif dengan hak akses yang sesuai.']);
        }

        $this->assertSame(4, EmployeeEvaluation::withoutCompany()->count());

        // Without evaluation.view a person reads their own evaluations alone, and their filter holds only themselves.
        $own = EmployeeEvaluation::factory()->create(['company_id' => $this->company->id, 'employee_id' => $ownOnly->id]);
        $theirs = EmployeeEvaluation::factory()->create(['company_id' => $this->company->id, 'employee_id' => $technician->id]);

        $mine = $this->actingAs($ownOnly)->get(route('admin.evaluations.index'))->assertOk()->inertiaProps('evaluations.data');
        $this->assertCount(2, $mine, 'the one the evaluator wrote above and the one made here');
        $this->assertSame([$ownOnly->public_id], collect($mine)->pluck('employee_id')->unique()->values()->all());
        $options = $this->get(route('admin.evaluations.index', ['employee_search' => 'Karyawan']), $this->partialReload('Admin/Evaluations/Index', 'employeeOptions'))
            ->assertOk()
            ->json('props.employeeOptions.*.label');
        $this->assertSame(['Karyawan Sendiri'], $options);
        $this->get(route('admin.evaluations.show', $own))->assertOk();
        $this->get(route('admin.evaluations.show', $theirs))->assertNotFound();

        // Roles add up (delta D1): a reviewing role on top of the same person lets them read everyone's.
        $this->grantPermissions($ownOnly, ['evaluation.view']);
        $this->get(route('admin.evaluations.show', $theirs))->assertOk();
    }

    public function test_technician_report_scopes_follow_permissions(): void
    {
        $managerUnit = OrganizationUnit::factory()->create(['company_id' => $this->company->id, 'path' => 'OPS', 'is_active' => true]);
        $descendantUnit = OrganizationUnit::factory()->create(['company_id' => $this->company->id, 'parent_id' => $managerUnit->id, 'path' => 'OPS > FIELD', 'is_active' => true]);
        $otherUnit = OrganizationUnit::factory()->create(['company_id' => $this->company->id, 'path' => 'SALES', 'is_active' => true]);
        $manager = $this->person('manager');
        $this->profile($manager, $managerUnit);
        $sameUnit = $this->technician($managerUnit);
        $descendant = $this->technician($descendantUnit);
        $other = $this->technician($otherUnit);
        // An employee profile does not make a subject of the report: the technician marker does.
        $staff = $this->person('staff');
        $this->profile($staff, $managerUnit);
        // Opens the report but holds neither scope.
        $viewer = $this->personWith(['report.technician']);
        $this->renameEveryRoleAndDeleteTheUnused();

        $listedFor = function (User $actor): array {
            $this->actingAs($actor);

            return TechnicianPerformanceQuery::eligibleTechnicians($actor)->orderBy('users.id')->pluck('users.id')->all();
        };
        $ids = fn (User ...$users): array => collect($users)->pluck('id')->sort()->values()->all();

        $this->assertSame($ids($sameUnit, $descendant, $other), $listedFor($this->admin), 'report.manage: every technician of the company');
        $this->assertSame($ids($sameUnit, $descendant), $listedFor($manager), 'report.technician.team: the unit and the units below it');
        $this->assertSame($ids($sameUnit), $listedFor($sameUnit), 'app.technician: themself');
        $this->assertSame([], $listedFor($viewer));

        foreach ([[$this->admin, true], [$manager, true], [$sameUnit, true], [$viewer, false], [$staff, false]] as [$actor, $allowed]) {
            $this->assertSame($allowed, TechnicianReportAccess::allows($actor), $actor->name);
        }

        $this->assertSame($descendant->id, TechnicianReportAccess::target($manager, $descendant->public_id)?->id);
        $this->assertThrows(fn () => TechnicianReportAccess::target($manager, $other->public_id), HttpException::class);
        $this->assertSame($sameUnit->id, TechnicianReportAccess::target($sameUnit, null)?->id);
        $this->assertNull(TechnicianReportAccess::target($manager, null));
        $this->assertNull(TechnicianReportAccess::target($this->admin, null));
    }

    public function test_web_access_follows_app_web(): void
    {
        $opens = $this->personWith(['app.web', 'dashboard.view']);
        $closed = $this->personWith(['dashboard.view', 'ticket.view']);
        $bare = $this->newUser($this->company);
        $direct = $this->newUser($this->company);
        $direct->givePermissionTo(['app.web', 'dashboard.view']);
        $technician = $this->person('technician');
        $customer = $this->person('customer');
        $noCompany = User::factory()->create(['company_id' => null, 'email_verified_at' => now(), 'is_active' => true]);
        $this->renameEveryRoleAndDeleteTheUnused();

        foreach ([$opens, $direct] as $user) {
            $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
            $this->get(route('app-only'))->assertRedirect(route('admin.dashboard'));
        }

        foreach ([$closed, $bare, $technician, $customer] as $user) {
            $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('app-only'));
        }

        foreach ([[$technician, 'technician'], [$customer, 'customer'], [$closed, null], [$bare, null]] as [$user, $app]) {
            $this->actingAs($user)->get(route('app-only'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('AppOnly')->where('role', $app));
        }

        // Nobody has a company to act in: the setup wizard decides, not the app page.
        $this->actingAs($noCompany)->get(route('admin.dashboard'))->assertRedirect('/setup');
    }

    public function test_customer_portal_and_app_follow_portal_access_and_the_link(): void
    {
        $linked = $this->person('customer');
        $this->customerRecord($linked);
        $unlinked = $this->person('customer');
        // The link alone opens nothing: this role holds portal permissions but not the portal marker.
        $notPortal = $this->personWith(['portal.invoice.view', 'ticket.view']);
        $this->customerRecord($notPortal);
        $this->renameEveryRoleAndDeleteTheUnused();

        $this->actingAs($linked)->get(route('portal.payments.return'))->assertOk();

        foreach ([$unlinked, $notPortal] as $user) {
            $this->actingAs($user)->get(route('portal.payments.return'))->assertForbidden();
        }

        $this->nobodyIsSignedIn();

        $this->assertNotEmpty($this->login($linked, 'customer'));

        foreach ([$unlinked, $notPortal] as $user) {
            $this->assertThrows(fn () => $this->login($user, 'customer'), ApiException::class);
        }
    }

    public function test_an_unlinked_portal_holder_sees_no_ticket(): void
    {
        $this->withoutMiddleware(RedirectAppOnlyRoles::class);
        $linked = $this->person('customer');
        $own = $this->customerRecord($linked);
        $unlinked = $this->person('customer');
        $this->renameEveryRoleAndDeleteTheUnused();
        $this->actingAs($this->admin);
        $ownTicket = $this->openTicket(['customer_id' => $own->id]);
        $this->openTicket(['customer_id' => Customer::factory()->create(['company_id' => $this->company->id])->id]);

        $this->actingAs($linked)->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.id', $ownTicket->public_id));

        $this->actingAs($unlinked)->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->get(route('admin.tickets.show', $ownTicket))->assertNotFound();
    }

    public function test_billing_schedule_alerts_reach_role_managers(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $manager = $this->person('manager');
        $custom = $this->personWith(['roles.manage']);
        $inactive = $this->person('admin', ['is_active' => false]);
        // Homed in another company, a member here with Admin here: told as well (delta D6).
        $member = $this->person('admin', [], $other);
        app(CompanyMembershipService::class)->grant($member, $this->company);
        $this->grantRole($member, 'admin', $this->company);
        $stranger = $this->person('admin', [], $other);
        $this->renameEveryRoleAndDeleteTheUnused();

        $this->assertEqualsCanonicalizing(
            [$this->admin->id, $custom->id, $member->id],
            BillingScheduleFailedNotification::recipients($this->company->id)->pluck('id')->all(),
        );

        Notification::fake();
        BillingScheduleFailedNotification::alertAdmins('billing:generate', [$this->company->id]);

        Notification::assertSentTo([$this->admin, $custom, $member], BillingScheduleFailedNotification::class);
        Notification::assertNotSentTo([$manager, $inactive, $stranger], BillingScheduleFailedNotification::class);
    }

    public function test_staff_recipients_resolve_per_company_without_a_signed_in_user(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $manager = $this->person('manager');
        $noc = $this->person('noc');
        $technician = $this->person('technician');
        $foreign = $this->person('manager', [], $other);
        $category = $this->openTicketCategory();
        $this->renameEveryRoleAndDeleteTheUnused();

        // The scheduler and a webhook run with nobody signed in and no company: the company comes from the record.
        $this->nobodyIsSignedIn();
        $holders = fn (Company $company, string $permission): array => StaffRecipients::holding($company->id, $permission)->pluck('id')->sort()->values()->all();
        $ids = fn (User ...$users): array => collect($users)->pluck('id')->sort()->values()->all();

        // The payment-exception alert goes to whoever records payments; dispatch notices to whoever assigns tickets.
        $this->assertSame($ids($this->admin, $manager), $holders($this->company, 'billing.payment.record'));
        $this->assertSame($ids($this->admin, $manager, $noc), $holders($this->company, 'ticket.assign'));
        $this->assertSame($ids($foreign), $holders($other, 'ticket.assign'));

        // The SLA job of the scheduler warns the dispatchers of the ticket's own company.
        Notification::fake();
        Ticket::withoutCompany()->create([
            'company_id' => $this->company->id,
            'code' => 'TKT-2026-00042',
            'title' => 'Internet mati',
            'description' => 'Tidak ada sinyal sejak pagi',
            'source' => 'customer',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'high',
            'assigned_to' => null,
            'created_by' => $this->admin->id,
            'sla_deadline' => now()->addHours(8),
            'response_deadline' => now()->addMinutes(20),
        ]);

        (new CheckTicketSlaBreachesJob($this->company->id))->handle();

        Notification::assertSentTo([$this->admin, $manager, $noc], TicketFirstResponseDueNotification::class);
        Notification::assertNotSentTo([$technician, $foreign], TicketFirstResponseDueNotification::class);
    }

    public function test_staff_recipients_count_a_member_by_his_roles_in_that_company_only(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $third = Company::factory()->create(['is_active' => true]);
        $memberships = app(CompanyMembershipService::class);
        // Admin here, only staff in the other company he is a member of.
        $both = $this->person('admin');
        $memberships->grant($both, $other);
        $this->grantRole($both, 'staff', $other);
        // The other way round: Admin at home in the other company, staff here.
        $away = $this->person('admin', [], $other);
        $memberships->grant($away, $this->company);
        $this->grantRole($away, 'staff', $this->company);
        $direct = $this->newUser($this->company);
        $direct->givePermissionTo('roles.manage');
        $inactive = $this->person('admin', ['is_active' => false]);
        $stranger = $this->person('admin', [], $third);
        $this->renameEveryRoleAndDeleteTheUnused();

        $holders = fn (Company $company, ?int $except = null): array => StaffRecipients::holding($company->id, 'roles.manage', $except)->pluck('id')->sort()->values()->all();
        $ids = fn (User ...$users): array => collect($users)->pluck('id')->sort()->values()->all();

        // Signed in as an administrator of this company: a notice of the other company still counts roles of the other.
        $this->actingAs($this->admin);
        $this->assertSame($ids($this->admin, $both, $direct), $holders($this->company));
        $this->assertSame($ids($this->admin, $direct), $holders($this->company, $both->id));
        $this->assertSame($ids($away), $holders($other));

        $this->nobodyIsSignedIn();
        $this->assertSame($ids($this->admin, $both, $direct), $holders($this->company));
        $this->assertSame($ids($away), $holders($other));
        $this->assertNotContains($inactive->id, $holders($this->company));
        $this->assertNotContains($stranger->id, [...$holders($this->company), ...$holders($other)]);
    }

    public function test_app_logins_resolve_permissions_in_the_users_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $technician = $this->person('technician');
        $this->profile($technician);
        // Technician in the other company he is a member of, but nothing that works tickets or SPK where he is at home.
        $away = $this->personWith(['ticket.view']);
        $this->profile($away);
        app(CompanyMembershipService::class)->grant($away, $other);
        $this->grantRole($away, 'technician', $other);
        $this->renameEveryRoleAndDeleteTheUnused();

        // Nobody is signed in when an app logs in, so no company is set either.
        $this->nobodyIsSignedIn();

        $this->assertNotEmpty($this->login($technician, 'technician'));
        $this->assertThrows(fn () => $this->login($away, 'technician'), ApiException::class);
    }

    public function test_going_home_needs_no_company_switch(): void
    {
        $subsidiary = Company::factory()->create(['is_active' => true]);
        $third = Company::factory()->create(['is_active' => true]);
        $user = $this->person('admin');
        // Staf opens the web but does not hold company.switch.
        foreach ([$subsidiary, $third] as $company) {
            app(CompanyMembershipService::class)->grant($user, $company);
            $this->grantRole($user, 'staff', $company);
        }
        $this->renameEveryRoleAndDeleteTheUnused();

        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $subsidiary->public_id])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSame($subsidiary->id, session(CompanyService::SESSION_KEY));

        // The switcher stays, with the way home and nothing else: he cannot switch to the third company from here.
        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('canSwitch', true)
            ->has('companies', 2)
            ->where('companies.0.is_default', true)
            ->where('companies.1.is_current', true));

        // The permission is read in the company he is in now: as staff there he cannot go on to another one ...
        $this->post(route('admin.company.switch'), ['company_id' => $third->public_id])->assertForbidden();
        // ... but going home needs none.
        $this->post(route('admin.company.switch'), ['company_id' => $this->company->public_id])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    /** An active, verified member of the company (its home company) holding the template role $key. */
    private function person(string $key, array $attributes = [], ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = $this->newUser($company, $attributes);
        $this->grantRole($user, $key, $company);

        return $user;
    }

    /** The same, holding a role of the company's own making that carries just these permissions. */
    private function personWith(array $permissions, array $attributes = []): User
    {
        return $this->grantPermissions($this->newUser($this->company, $attributes), $permissions, $this->company);
    }

    /** A member of the company with no role at all. */
    private function newUser(Company $company, array $attributes = []): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
            ...$attributes,
        ]);
        app(CompanyMembershipService::class)->grant($user, $company, true);

        return $user;
    }

    private function profile(User $user, ?OrganizationUnit $unit = null): EmployeeProfile
    {
        return EmployeeProfile::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'organization_id' => $unit?->id,
            'status' => 'active',
        ]);
    }

    private function technician(?OrganizationUnit $unit = null): User
    {
        $technician = $this->person('technician', ['name' => 'Teknisi '.fake()->unique()->numerify('######')]);
        $this->profile($technician, $unit);

        return $technician;
    }

    private function customerRecord(User $user): Customer
    {
        return Customer::factory()->create(['company_id' => $this->company->id, 'user_id' => $user->id]);
    }

    /** What a company can do in Peran: give every role a name nothing could match, and delete the roles nobody holds. */
    private function renameEveryRoleAndDeleteTheUnused(): void
    {
        DB::table('roles')->update(['name' => DB::raw("CONCAT('Peran ', id)")]);
        DB::table('roles')->whereNotIn('id', DB::table('model_has_roles')->select('role_id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A webhook, a queued job and an app login have no session and no company in the request. */
    private function nobodyIsSignedIn(): void
    {
        auth()->forgetGuards();
        CompanyService::resetCache();
    }

    private function login(User $user, string $app): string
    {
        return app(ApiTokenService::class)->issue($user, $app, 'Test phone')->plainTextToken;
    }

    private function openTicketCategory(): TicketCategory
    {
        return $this->category ??= TicketCategory::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Gangguan',
            'code' => 'GNG',
            'default_sla_hours' => 8,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
    }

    /**
     * An open ticket made as the admin, who must be the signed-in user.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function openTicket(array $overrides = []): Ticket
    {
        return TicketService::create([
            'title' => 'Internet mati',
            'description' => 'Tidak ada sinyal sejak pagi',
            'source' => 'internal',
            'category_id' => $this->openTicketCategory()->id,
            'priority' => 'medium',
            ...$overrides,
        ], $this->admin->id);
    }
}
