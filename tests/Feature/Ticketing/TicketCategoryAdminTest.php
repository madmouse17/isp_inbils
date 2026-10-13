<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

class TicketCategoryAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->category = $this->category($this->company, 'no_internet', 'Tanpa Internet', 4, 2);
    }

    public function test_admin_and_manager_list_categories_with_the_contract_props(): void
    {
        $this->category($this->company, 'fiber', 'Fiber', 12, 6, false);
        $this->category(Company::factory()->create(), 'foreign', 'Foreign', 8, 4);

        foreach (['admin', 'manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('admin.ticket-categories.index'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Categories/Index')
                ->has('categories', 2)
                ->where('categories.0.name', 'Fiber')
                ->where('categories.0.is_active', false)
                ->where('categories.1', fn ($row) => $row['code'] === 'no_internet'
                    && $row['default_sla_hours'] === 4
                    && $row['default_response_hours'] === 2
                    && $row['is_active'] === true)
                ->where('can.manage', true));
        }
    }

    public function test_staff_gets_403_and_app_only_personas_are_redirected(): void
    {
        foreach (['staff', 'technician', 'customer'] as $role) {
            $user = $this->user($role);
            // App-only personas are bounced by redirect.app.only before the policy runs; staff reach it and get 403.
            $expected = $role === 'staff' ? 403 : 302;
            $this->actingAs($user)->get(route('admin.ticket-categories.index'))->assertStatus($expected);
            $this->actingAs($user)->put(route('admin.ticket-categories.update', $this->category), $this->payload())->assertStatus($expected);
        }

        $this->assertSame(4, $this->category->fresh()->default_sla_hours);
    }

    public function test_manager_updates_name_targets_and_status(): void
    {
        $this->actingAs($this->user('manager'))->from(route('admin.ticket-categories.index'))
            ->put(route('admin.ticket-categories.update', $this->category), $this->payload(['name' => 'Internet Mati', 'default_sla_hours' => 10, 'default_response_hours' => 3, 'is_active' => false]))
            ->assertRedirect(route('admin.ticket-categories.index'));

        $this->assertDatabaseHas('ticket_categories', [
            'id' => $this->category->id, 'name' => 'Internet Mati', 'code' => 'no_internet',
            'default_sla_hours' => 10, 'default_response_hours' => 3, 'is_active' => false,
        ]);
    }

    public function test_validation_bounds_and_response_not_longer_than_resolution(): void
    {
        $manager = $this->user('manager');
        $url = route('admin.ticket-categories.update', $this->category);

        foreach ([
            [['default_sla_hours' => 0], 'default_sla_hours'],
            [['default_sla_hours' => 721], 'default_sla_hours'],
            [['default_response_hours' => 0], 'default_response_hours'],
            [['default_response_hours' => 721], 'default_response_hours'],
            [['default_sla_hours' => 4, 'default_response_hours' => 5], 'default_response_hours'],
            [['name' => ''], 'name'],
            [['is_active' => 'nope'], 'is_active'],
        ] as [$override, $field]) {
            $this->actingAs($manager)->put($url, $this->payload($override))->assertSessionHasErrors($field);
        }

        $this->actingAs($manager)->put($url, $this->payload(['default_sla_hours' => 720, 'default_response_hours' => 720]))->assertSessionHasNoErrors();
        $this->assertSame(720, $this->category->fresh()->default_response_hours);
    }

    public function test_a_foreign_company_category_is_not_found_and_stays_unchanged(): void
    {
        $foreign = $this->category(Company::factory()->create(), 'foreign', 'Foreign', 8, 4);

        $this->actingAs($this->user('admin'))->put(route('admin.ticket-categories.update', $foreign->id), $this->payload())->assertNotFound();

        $this->assertSame('Foreign', TicketCategory::withoutCompany()->find($foreign->id)->name);
    }

    public function test_permission_is_granted_to_admin_and_manager_only(): void
    {
        foreach (['admin' => true, 'manager' => true, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $allowed) {
            $this->assertSame($allowed, $this->user($role)->can('ticket.category.manage'), $role);
        }
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return $override + ['name' => 'Tanpa Internet', 'default_sla_hours' => 4, 'default_response_hours' => 2, 'is_active' => true];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function category(Company $company, string $code, string $name, int $sla, int $response, bool $active = true): TicketCategory
    {
        return TicketCategory::query()->forceCreate([
            'company_id' => $company->id, 'code' => $code, 'name' => $name, 'default_sla_hours' => $sla,
            'default_response_hours' => $response, 'default_priority' => 'medium', 'is_active' => $active,
        ]);
    }
}
