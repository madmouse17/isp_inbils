<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\RoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M-7: `ticket.reopen` goes to the Manajer and NOC templates and to every company role copied from them, and nowhere else.
 * The permission existed before (the Admin template holds it), so taking the release back removes only the two grants.
 * The migration is data only, so the test runs it in place and leaves the database as it found it.
 */
class TicketReopenReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_02_100700_release_ticket_reopen_to_templates.php';

    private const GRANTED = ['manager', 'noc'];

    private const NOT_GRANTED = ['technician', 'warehouse', 'finance', 'staff', 'customer'];

    public function test_the_release_reaches_the_two_templates_and_their_copies_and_nothing_else(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $copies = [$this->companyRoles($alpha), $this->companyRoles($beta)];
        // A role a company made itself is its own: a release never reaches it.
        $custom = $this->companyRole($alpha, 'Penyelia', ['ticket.view']);
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertGrants(granted: ['admin'], copies: $copies, custom: $custom, expectedCopies: 2);

        $migration->up();
        $migration->up();
        $this->assertGrants(granted: ['admin', ...self::GRANTED], copies: $copies, custom: $custom, expectedCopies: 6);
    }

    public function test_taking_it_back_leaves_the_permission_and_the_admin_template(): void
    {
        $company = Company::factory()->create();
        $copies = [$this->companyRoles($company)];
        $migration = require base_path(self::MIGRATION);

        $migration->down();

        $this->assertSame(1, DB::table('permissions')->where('name', 'ticket.reopen')->where('guard_name', 'web')->count(), 'it existed before this release');
        $this->assertTrue(RoleTemplate::query()->where('key', 'admin')->firstOrFail()->permissions()->where('name', 'ticket.reopen')->exists());
        $this->assertTrue($copies[0]['admin']->permissions()->where('name', 'ticket.reopen')->exists());
        foreach (self::GRANTED as $key) {
            $this->assertFalse(RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->where('name', 'ticket.reopen')->exists(), "template {$key}");
            $this->assertFalse($copies[0][$key]->permissions()->where('name', 'ticket.reopen')->exists(), "copy of {$key}");
        }
    }

    public function test_a_company_made_after_the_release_starts_with_the_grant(): void
    {
        $roles = $this->companyRoles(Company::factory()->create());

        foreach (self::GRANTED as $key) {
            $this->assertTrue($roles[$key]->permissions()->where('name', 'ticket.reopen')->exists(), $key);
        }
        foreach (self::NOT_GRANTED as $key) {
            $this->assertFalse($roles[$key]->permissions()->where('name', 'ticket.reopen')->exists(), $key);
        }
    }

    /**
     * @param  list<string>  $granted  template keys that hold the permission now
     * @param  list<array<string, Role>>  $copies  the company copies of every template, one list per company
     */
    private function assertGrants(array $granted, array $copies, Role $custom, int $expectedCopies): void
    {
        foreach (['admin', ...self::GRANTED, ...self::NOT_GRANTED] as $key) {
            $holds = in_array($key, $granted, true);

            $this->assertSame($holds, RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->where('name', 'ticket.reopen')->exists(), "template {$key}");

            foreach ($copies as $roles) {
                $this->assertSame($holds, $roles[$key]->permissions()->where('name', 'ticket.reopen')->exists(), "copy of {$key}");
            }
        }

        $this->assertFalse($custom->permissions()->where('name', 'ticket.reopen')->exists(), 'the role a company made itself');
        $this->assertSame($expectedCopies, DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->where('permissions.name', 'ticket.reopen')->whereNotNull('roles.template_key')->count());
    }
}
