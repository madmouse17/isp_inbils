<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Which tables hold a tenant's rows and in what order a purge may delete them, read from the server schema so a new
 * tenant table is reached the day its migration runs. A tenant table is any table with a `company_id` column.
 *
 * - tables(): every tenant table, children before the parents their foreign keys point to. The tenant export of a
 *   later ticket walks the same list.
 * - detachedColumns(): foreign-key columns a purge sets to NULL before it deletes, so the order can ignore them:
 *   the self-references of a tree (`parent_id`) and the one real cycle between two tables (CYCLE_BREAKERS).
 * - pivots(): tables without a `company_id` that only link tenant rows (an announcement and its locations). They are
 *   deleted first, found through their parents.
 *
 * Every table and column name that reaches SQL was read from the schema and matches /^[a-z0-9_]+$/; anything else
 * stops the build. Tables that belong to no tenant (role templates, permissions, settings, jobs, regions, ...) never
 * appear; `roles` carries a `company_id` since the roles became per company, so it is a tenant table.
 */
final class TenantTableRegistry
{
    /**
     * Two tables that reference each other cannot be sorted. The column listed here is nulled first, then the
     * reference counts as gone. It must accept null and no CHECK constraint may involve it:
     * odn_ports_kind_consistent forbids nulling odn_ports.odn_splitter_id, so the port a splitter hangs on is cut.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const CYCLE_BREAKERS = [['odn_splitters', 'input_port_id']];

    /** @var list<string>|null */
    private ?array $tables = null;

    /** @var list<array{table: string, column: string}> */
    private array $detached = [];

    /** @var list<array{table: string, parents: array<string, array{table: string, column: string}>}> */
    private array $pivots = [];

    /** @return list<string> children first */
    public function tables(): array
    {
        $this->load();

        return $this->tables ?? [];
    }

    /** @return list<array{table: string, column: string}> */
    public function detachedColumns(): array
    {
        $this->load();

        return $this->detached;
    }

    /**
     * @return list<array{table: string, parents: array<string, array{table: string, column: string}>}> each with foreign-key column => the tenant table and column it points to
     */
    public function pivots(): array
    {
        $this->load();

        return $this->pivots;
    }

    private function load(): void
    {
        if ($this->tables !== null) {
            return;
        }

        $names = Schema::getTableListing(DB::connection()->getDatabaseName(), false);
        sort($names);

        /** @var array<string, array<string, bool>> $nullable table => column => accepts null */
        $nullable = [];

        foreach ($names as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $nullable[$table][$column['name']] = (bool) $column['nullable'];
            }
        }

        $tenant = array_values(array_filter($names, fn (string $table): bool => isset($nullable[$table]['company_id'])));

        /** @var array<string, list<array{columns: list<string>, parent: string, parent_columns: list<string>}>> $foreignKeys */
        $foreignKeys = [];

        foreach ($names as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                $foreignKeys[$table][] = [
                    'columns' => $this->listOf($key['columns']),
                    'parent' => $key['foreign_table'],
                    'parent_columns' => $this->listOf($key['foreign_columns']),
                ];
            }
        }

        $this->detached = $this->cycleBreakers($nullable);
        $children = [];

        foreach ($tenant as $child) {
            foreach ($foreignKeys[$child] ?? [] as $key) {
                if (! in_array($key['parent'], $tenant, true)) {
                    continue;
                }

                if ($key['parent'] === $child) {
                    foreach ($key['columns'] as $column) {
                        $this->detach($nullable, $child, $column);
                    }

                    continue;
                }

                if (count($key['columns']) === 1 && $this->isDetached($child, $key['columns'][0])) {
                    continue;
                }

                $children[$key['parent']][$child] = true;
            }
        }

        $tables = $this->sort($tenant, $children);
        $pivots = $this->findPivots($names, $tenant, $foreignKeys);
        $this->checkIdentifiers($tables, $this->detached, $pivots);

        $this->tables = $tables;
        $this->pivots = $pivots;
    }

    /**
     * @param  array<string, array<string, bool>>  $nullable
     * @return list<array{table: string, column: string}>
     */
    private function cycleBreakers(array $nullable): array
    {
        $detached = [];

        foreach (self::CYCLE_BREAKERS as [$table, $column]) {
            if (! isset($nullable[$table][$column])) {
                throw new RuntimeException("TenantTableRegistry::CYCLE_BREAKERS names {$table}.{$column}, which is not in the schema. Update the list.");
            }

            if (! $nullable[$table][$column]) {
                throw new RuntimeException("{$table}.{$column} is nulled before a purge, so it must accept null.");
            }

            $detached[] = ['table' => $table, 'column' => $column];
        }

        return $detached;
    }

    /** @param  array<string, array<string, bool>>  $nullable */
    private function detach(array $nullable, string $table, string $column): void
    {
        if (! $nullable[$table][$column]) {
            throw new RuntimeException("{$table}.{$column} points at its own table and is not nullable: a purge cannot null it before the delete.");
        }

        if (! $this->isDetached($table, $column)) {
            $this->detached[] = ['table' => $table, 'column' => $column];
        }
    }

    private function isDetached(string $table, string $column): bool
    {
        return in_array(['table' => $table, 'column' => $column], $this->detached, true);
    }

    /**
     * @param  list<string>  $tenant
     * @param  array<string, array<string, true>>  $children  parent => the tables that point at it
     * @return list<string>
     */
    private function sort(array $tenant, array $children): array
    {
        $remaining = array_flip($tenant);
        $ordered = [];

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                array_keys($remaining),
                fn (string $table): bool => array_intersect_key($children[$table] ?? [], $remaining) === [],
            ));

            if ($ready === []) {
                throw new RuntimeException('Tenant tables reference each other in a cycle ('.implode(', ', array_keys($remaining)).'). Add the column that may be nulled first to TenantTableRegistry::CYCLE_BREAKERS.');
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $tenant
     * @param  array<string, list<array{columns: list<string>, parent: string, parent_columns: list<string>}>>  $foreignKeys
     * @return list<array{table: string, parents: array<string, array{table: string, column: string}>}>
     */
    private function findPivots(array $names, array $tenant, array $foreignKeys): array
    {
        $pivots = [];

        foreach ($names as $table) {
            if (in_array($table, $tenant, true)) {
                continue;
            }

            $parents = [];

            foreach ($foreignKeys[$table] ?? [] as $key) {
                if (! in_array($key['parent'], $tenant, true)) {
                    continue;
                }

                if (count($key['columns']) !== 1) {
                    throw new RuntimeException("{$table} links tenant rows with a composite foreign key; teach TenantTableRegistry to read it before a purge.");
                }

                $parents[$key['columns'][0]] = ['table' => $key['parent'], 'column' => $key['parent_columns'][0]];
            }

            if ($parents !== []) {
                ksort($parents);
                $pivots[] = ['table' => $table, 'parents' => $parents];
            }
        }

        return $pivots;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<array{table: string, column: string}>  $detached
     * @param  list<array{table: string, parents: array<string, array{table: string, column: string}>}>  $pivots
     */
    private function checkIdentifiers(array $tables, array $detached, array $pivots): void
    {
        $names = [...$tables, ...array_column($detached, 'table'), ...array_column($detached, 'column')];

        foreach ($pivots as $pivot) {
            $names[] = $pivot['table'];

            foreach ($pivot['parents'] as $column => $parent) {
                array_push($names, $column, $parent['table'], $parent['column']);
            }
        }

        foreach ($names as $name) {
            if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                throw new RuntimeException("[{$name}] is not a plain lowercase identifier: the tenant registry builds SQL from names only when they are.");
            }
        }
    }

    /**
     * MySQL reports the columns of one foreign key as a comma-joined string, other drivers as a list.
     *
     * @param  string|list<string>  $value
     * @return list<string>
     */
    private function listOf(string|array $value): array
    {
        return is_array($value) ? $value : explode(',', $value);
    }
}
