<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Core\Location;
use App\Traits\HasLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Product;
use Modules\Service\Models\ServicePackage;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * lifecycleReferences() is what keeps a hard delete from ever reaching a foreign key: LifecycleService counts the
 * declared columns and refuses. A foreign key nobody declared would turn into a database error, so the declarations
 * are checked against the schema the migrations really build.
 */
class LifecycleReferencesMatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Foreign keys a hard delete may meet because the delete itself clears them (model table => "table.column"). An
     * asset's IP addresses are released before the row goes (IpAssignmentService::releaseForAsset).
     */
    private const CLEARED_BY_THE_DELETE = [
        'network_assets' => ['ip_addresses.network_asset_id'],
    ];

    public function test_model_discovery_reaches_every_models_directory_and_finds_the_lifecycle_models(): void
    {
        $models = $this->models();

        $this->assertContains(Location::class, $models, 'app/Models');
        $this->assertContains(Product::class, $models, 'Modules/*/app/Models');
        $this->assertContains(ServicePackage::class, $models, 'Modules/*/Models');
        $this->assertContains(ServicePackage::class, $this->lifecycleModels(), 'a model that uses HasLifecycle is among those checked');
    }

    public function test_every_restrict_or_set_null_foreign_key_to_a_lifecycle_table_is_declared(): void
    {
        $undeclared = [];

        foreach ($this->lifecycleModels() as $class) {
            $missing = $this->undeclared((new $class())->getTable(), $this->declaredColumns($class));

            if ($missing !== []) {
                $undeclared[$class] = $missing;
            }
        }

        $this->assertSame([], $undeclared, 'Declare these in lifecycleReferences(), or list them in CLEARED_BY_THE_DELETE when the delete itself clears them.');
    }

    public function test_the_check_reads_the_delete_rules_of_the_schema(): void
    {
        $customers = $this->undeclared('customers', []);

        $this->assertContains('service_subscriptions.customer_id', $customers, 'RESTRICT blocks a delete');
        $this->assertNotContains('customer_addresses.customer_id', $customers, 'CASCADE does not');
        $this->assertContains('employee_profiles.vehicle_id', $this->undeclared('vehicles', []), 'SET NULL blocks a delete');
        $this->assertNotContains('employee_profiles.vehicle_id', $this->undeclared('vehicles', ['employee_profiles.vehicle_id']));
        $this->assertContains('ip_addresses.network_asset_id', $this->blockingForeignKeys('network_assets'), 'the allowlist entry names a real key');
        $this->assertNotContains('ip_addresses.network_asset_id', $this->undeclared('network_assets', []), 'the delete clears it');
    }

    public function test_every_declared_reference_column_exists(): void
    {
        $unknown = [];

        foreach ($this->lifecycleModels() as $class) {
            foreach ($this->declaredColumns($class) as $reference) {
                [$table, $column] = explode('.', $reference, 2);

                if (! Schema::hasColumn($table, explode('->', $column, 2)[0])) {
                    $unknown[$class][] = $reference;
                }
            }
        }

        $this->assertSame([], $unknown);
    }

    /** @return list<class-string> every concrete class under a models directory, found by its namespace and class lines */
    private function models(): array
    {
        $directories = array_filter(
            [app_path('Models'), ...(glob(base_path('Modules/*/app/Models')) ?: []), ...(glob(base_path('Modules/*/Models')) ?: [])],
            'is_dir',
        );
        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories) as $file) {
            $source = $file->getContents();

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class)) {
                $classes[] = $namespace[1].'\\'.$class[1];
            }
        }

        sort($classes);

        return $classes;
    }

    /** @return list<class-string> */
    private function lifecycleModels(): array
    {
        return array_values(array_filter(
            $this->models(),
            fn (string $class): bool => in_array(HasLifecycle::class, class_uses_recursive($class), true) && $class::lifecycleReferences() !== null,
        ));
    }

    /** @return list<string> */
    private function declaredColumns(string $class): array
    {
        return array_merge(...array_values($class::lifecycleReferences()));
    }

    /**
     * @param  list<string>  $declared
     * @return list<string> the blocking keys to $table that the declaration does not name and the delete does not clear
     */
    private function undeclared(string $table, array $declared): array
    {
        return array_values(array_diff($this->blockingForeignKeys($table), $declared, self::CLEARED_BY_THE_DELETE[$table] ?? []));
    }

    /** @return list<string> "table.column" of every foreign key to $table that stops a delete of its row */
    private function blockingForeignKeys(string $table): array
    {
        $keys = DB::select(<<<'SQL'
            SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME = ?
              AND r.DELETE_RULE IN ('RESTRICT', 'NO ACTION', 'SET NULL')
            SQL, [$table]);

        return array_map(fn (object $key): string => $key->child_table.'.'.$key->child_column, $keys);
    }
}
