<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Service\Models\ServicePackageVersion;
use Tests\TestCase;

/**
 * The inventory of the opaque-ids lane (spec .scratch/opaque-ids, ticket 01) as an executable contract: the tables that
 * carry `public_id`, grouped by the migration that adds them. A table joins the list when a URL, an API path or a
 * payload names its rows; it leaves it only with the spec's say-so. Not on the list, and why:
 * - permissions: addressed by name everywhere (the role editor posts names), so no id leaves the server for them;
 * - notifications: the primary key already is a UUID; failed_jobs: Laravel's own `uuid` column serves;
 * - pivots and the company_user membership table: no surrogate id; settings: keyed by name; port_templates: the
 *   catalogue of device faces, shared by every company and addressed by its `key`;
 * - sessions, jobs, cache, migrations and the region tables: framework or reference data;
 * - service_package_versions: append-only rows reached through their package or subscription. The payload carries the
 *   version number, which names the version inside its package, so no id leaves the server for them.
 * - company_integrations: one row per company and provider, addressed by the provider name in the URL; no page or payload
 *   carries an id for a row, and the platform panel shows the states of a tenant without naming a row.
 */
class PublicIdInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> migration group => tables */
    public const TABLES = [
        'core' => [
            'companies', 'users', 'roles', 'media', 'activity_log', 'personal_access_tokens', 'device_tokens',
            'platform_admins', 'tenant_exports', 'customers', 'customer_addresses', 'customer_contacts',
            'document_types', 'employee_evaluations', 'employee_profiles', 'locations', 'number_sequences',
            'organization_units', 'service_subscriptions', 'vehicles', 'whatsapp_messages', 'whatsapp_inbound_receipts',
        ],
        'billing' => ['invoices', 'invoice_items', 'payments', 'payment_attempts'],
        'inventory' => [
            'categories', 'units', 'products', 'stocks', 'stock_movements', 'stock_adjustment_requests',
            'inventory_requests', 'inventory_request_items', 'material_return_cases', 'material_return_events',
            'material_return_lines',
        ],
        'ipam' => ['ip_addresses', 'ip_prefixes', 'vlans'],
        'network_asset' => ['nas_devices', 'network_accounts', 'network_asset_installations', 'network_assets', 'odn_ports', 'odn_splitters'],
        // Its own group: asset_ports is created with its public_id (2026_10_24_110200), so the 2026_10_22 migration above does not touch it.
        'hardware_panel' => ['asset_ports'],
        // Its own group: role_templates is created with its public_id (2026_10_29_100000), so the 2026_10_22 migration above does not touch it.
        'role_templates' => ['role_templates'],
        'service' => ['announcements', 'promo_banners', 'bandwidth_profiles', 'service_package_categories', 'service_packages', 'sla_tiers', 'speed_profiles'],
        'spk' => ['work_orders', 'work_order_assignments', 'work_order_items'],
        'ticketing' => ['tickets', 'ticket_categories', 'ticket_comments'],
    ];

    /** Package tables: no model of ours to put the trait on, so AppServiceProvider fills the column on create. */
    private const PACKAGE_TABLES = ['media', 'activity_log', 'personal_access_tokens'];

    /** Tenant models that carry no `public_id`, and why: see the class comment. */
    private const WITHOUT_PUBLIC_ID = [ServicePackageVersion::class, CompanyIntegration::class];

    /** @return list<string> */
    private function listed(): array
    {
        return array_merge(...array_values(self::TABLES));
    }

    public function test_every_listed_table_has_a_unique_not_null_char_26_public_id(): void
    {
        foreach ($this->listed() as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'public_id');

            $this->assertNotNull($column, "{$table} has no public_id");
            $this->assertSame('char(26)', $column['type'], "{$table}.public_id");
            $this->assertFalse($column['nullable'], "{$table}.public_id must be NOT NULL");
            $this->assertTrue(Schema::hasIndex($table, ['public_id'], 'unique'), "{$table}.public_id must be unique");
        }
    }

    public function test_no_other_table_carries_a_public_id(): void
    {
        $carrying = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('column_name', 'public_id')
            ->selectRaw('LOWER(table_name) AS name')
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing($this->listed(), $carrying);
    }

    public function test_every_tenant_model_uses_the_trait(): void
    {
        $without = array_values(array_filter(
            $this->models(),
            fn (string $model): bool => in_array(BelongsToCompany::class, class_uses_recursive($model), true)
                && ! in_array(HasPublicId::class, class_uses_recursive($model), true)
                && ! in_array($model, self::WITHOUT_PUBLIC_ID, true),
        ));

        $this->assertSame([], $without, 'a BelongsToCompany model must use HasPublicId, and its table must join the inventory');
    }

    public function test_the_trait_models_and_the_inventory_agree_on_the_tables(): void
    {
        $withTrait = array_values(array_filter($this->models(), fn (string $model): bool => in_array(HasPublicId::class, class_uses_recursive($model), true)));
        $tables = array_map(fn (string $model): string => (new $model())->getTable(), $withTrait);

        $this->assertContains(User::class, $withTrait, 'the scan must really find the models');
        $this->assertSame([], array_values(array_diff($tables, $this->listed())), 'a model with the trait whose table is not on the inventory');
        $this->assertSame([], array_values(array_diff($this->listed(), $tables, self::PACKAGE_TABLES)), 'a listed table that no model fills');
    }

    /** @return list<class-string<Model>> every Eloquent model of the core and of the modules */
    private function models(): array
    {
        $files = array_merge(
            glob(app_path('Models/*.php')) ?: [],
            glob(app_path('Models/*/*.php')) ?: [],
            glob(base_path('Modules/*/app/Models/*.php')) ?: [],
            glob(base_path('Modules/Service/Models/*.php')) ?: [],
        );
        $models = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class)) {
                $name = $namespace[1].'\\'.$class[1];

                if (is_subclass_of($name, Model::class)) {
                    $models[] = $name;
                }
            }
        }

        $this->assertGreaterThan(40, count($models), 'the scan must really find the models');

        return $models;
    }
}
