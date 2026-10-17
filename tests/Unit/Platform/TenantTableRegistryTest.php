<?php

namespace Tests\Unit\Platform;

use App\Support\Platform\TenantTableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The registry decides what a tenant purge (and the tenant export of a later ticket) reaches. These tests read the
 * schema a second time, straight from information_schema, so a table nobody registered cannot slip past the
 * registry's own introspection, and so the delete order is checked against the real foreign keys.
 */
class TenantTableRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_with_a_company_id_column_is_registered(): void
    {
        $withCompanyId = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('column_name', 'company_id')
            ->selectRaw('LOWER(table_name) AS name')
            ->pluck('name')
            ->all();

        $this->assertGreaterThan(40, count($withCompanyId));
        $this->assertEqualsCanonicalizing($withCompanyId, $this->registry()->tables());
    }

    public function test_the_tables_the_purger_treats_specially_are_registered_too(): void
    {
        $tables = $this->registry()->tables();

        foreach (['users', 'company_user', 'activity_log', 'tenant_exports', 'customers', 'odn_ports', 'odn_splitters', 'tickets', 'work_orders'] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function test_children_are_deleted_before_the_parents_that_their_foreign_keys_point_to(): void
    {
        $registry = $this->registry();
        $position = array_flip($registry->tables());
        $detached = array_map(fn (array $d): string => $d['table'].'.'.$d['column'], $registry->detachedColumns());
        $checked = 0;

        foreach ($this->foreignKeys() as $key) {
            if (! isset($position[$key->child], $position[$key->parent]) || $key->child === $key->parent) {
                continue;
            }

            if (in_array($key->child.'.'.$key->column, $detached, true)) {
                continue;
            }

            $checked++;
            $this->assertLessThan($position[$key->parent], $position[$key->child], "{$key->child}.{$key->column} points at {$key->parent}: the child must be deleted first.");
        }

        $this->assertGreaterThan(100, $checked, 'the check must really walk the foreign keys');
    }

    public function test_self_references_and_the_fibre_cycle_are_cut_by_nulling_a_nullable_column_first(): void
    {
        $detached = $this->registry()->detachedColumns();
        $named = array_map(fn (array $d): string => $d['table'].'.'.$d['column'], $detached);

        foreach (['locations.parent_id', 'organization_units.parent_id', 'categories.parent_id', 'ip_prefixes.parent_id', 'odn_splitters.input_port_id'] as $expected) {
            $this->assertContains($expected, $named);
        }

        foreach ($detached as $d) {
            $nullable = DB::table('information_schema.columns')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $d['table'])
                ->where('column_name', $d['column'])
                ->value('is_nullable');

            $this->assertSame('YES', $nullable, "{$d['table']}.{$d['column']} is nulled before the delete, so it must accept null");
        }
    }

    public function test_the_pivot_tables_without_a_company_id_are_found_through_their_parents(): void
    {
        $pivots = collect($this->registry()->pivots())->keyBy('table');

        $this->assertEqualsCanonicalizing(
            ['announcement_location', 'announcement_service_package_category', 'device_tokens', 'location_promo_banner', 'promo_banner_service_package_category'],
            $pivots->keys()->all(),
        );
        $this->assertSame(['table' => 'users', 'column' => 'id'], $pivots['device_tokens']['parents']['user_id']);
        $this->assertSame(
            ['announcement_id' => ['table' => 'announcements', 'column' => 'id'], 'location_id' => ['table' => 'locations', 'column' => 'id']],
            $pivots['announcement_location']['parents'],
        );
        $this->assertSame(
            ['promo_banner_id' => ['table' => 'promo_banners', 'column' => 'id'], 'service_package_category_id' => ['table' => 'service_package_categories', 'column' => 'id']],
            $pivots['promo_banner_service_package_category']['parents'],
        );
    }

    public function test_global_and_infrastructure_tables_are_never_reached(): void
    {
        $reached = [...$this->registry()->tables(), ...array_column($this->registry()->pivots(), 'table')];

        foreach (['companies', 'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'settings', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'migrations', 'sessions', 'notifications', 'media', 'personal_access_tokens', 'indonesia_provinces', 'indonesia_villages', 'platform_admins'] as $table) {
            $this->assertNotContains($table, $reached);
        }
    }

    public function test_every_identifier_is_a_plain_lowercase_name(): void
    {
        $registry = $this->registry();

        foreach ([...$registry->tables(), ...array_column($registry->pivots(), 'table'), ...array_column($registry->detachedColumns(), 'table'), ...array_column($registry->detachedColumns(), 'column')] as $identifier) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $identifier);
        }
    }

    private function registry(): TenantTableRegistry
    {
        return app(TenantTableRegistry::class);
    }

    /** @return list<object{child: string, column: string, parent: string}> */
    private function foreignKeys(): array
    {
        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->whereNotNull('referenced_table_name')
            ->selectRaw('LOWER(table_name) AS child, column_name AS `column`, LOWER(referenced_table_name) AS parent')
            ->get()
            ->all();
    }
}
