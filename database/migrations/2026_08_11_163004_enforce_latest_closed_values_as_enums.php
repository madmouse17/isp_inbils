<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** @var array<string, array<string, array{values: array<int, string>, default?: string}>> */
    private array $columns = [
        'settings' => ['type' => ['values' => ['string', 'boolean', 'integer', 'float', 'json'], 'default' => 'string']],
        'locations' => ['type' => ['values' => ['region', 'area', 'pop', 'rack', 'site']]],
        'customers' => ['type' => ['values' => ['Individual', 'Company']]],
        'service_subscriptions' => ['status' => ['values' => ['pending', 'active', 'suspended', 'terminated'], 'default' => 'pending']],
        'organization_units' => ['type' => ['values' => ['company', 'branch', 'area', 'unit', 'team'], 'default' => 'branch']],
        'employee_profiles' => ['status' => ['values' => ['active', 'inactive', 'terminated'], 'default' => 'active']],
        'products' => ['type' => ['values' => ['consumable', 'asset', 'service'], 'default' => 'consumable']],
        'stock_movements' => ['movement_type' => ['values' => ['receive', 'issue', 'transfer', 'adjustment', 'reserve', 'release', 'return']]],
        'network_assets' => [
            'asset_type' => ['values' => ['router', 'switch', 'olt', 'onu_ont', 'radio', 'antenna', 'fiber', 'odp', 'odc', 'rack', 'power', 'other']],
            'status' => ['values' => ['available', 'installed', 'maintenance', 'damaged', 'retired'], 'default' => 'available'],
            'ownership' => ['values' => ['owned', 'leased', 'customer_provided'], 'default' => 'owned'],
        ],
        'bandwidth_profiles' => ['type' => ['values' => ['shared', 'dedicated'], 'default' => 'shared']],
        'work_orders' => [
            'type' => ['values' => ['installation', 'maintenance', 'upgrade_service', 'relocation']],
            'status' => ['values' => ['draft', 'generated', 'assigned', 'in_progress', 'waiting_review', 'completed', 'rejected', 'cancelled'], 'default' => 'draft'],
            'source' => ['values' => ['manual', 'ticket', 'subscription', 'monitoring'], 'default' => 'manual'],
            'priority' => ['values' => ['low', 'medium', 'high', 'urgent'], 'default' => 'medium'],
        ],
        'invoices' => [
            'type' => ['values' => ['one_time', 'recurring']],
            'source' => ['values' => ['manual', 'subscription', 'spk']],
            'status' => ['values' => ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'], 'default' => 'draft'],
        ],
        'payments' => ['method' => ['values' => ['cash', 'transfer', 'cheque', 'other']]],
        'ticket_categories' => ['default_priority' => ['values' => ['low', 'medium', 'high', 'urgent'], 'default' => 'medium']],
        'tickets' => [
            'source' => ['values' => ['customer', 'noc', 'internal']],
            'status' => ['values' => ['open', 'assigned', 'on_progress', 'resolved', 'closed'], 'default' => 'open'],
            'priority' => ['values' => ['low', 'medium', 'high', 'urgent'], 'default' => 'medium'],
        ],
    ];

    public function up(): void
    {
        DB::table('tickets')->where('source', 'manual')->update(['source' => 'internal']);
        DB::table('invoices')->where('status', 'issued')->update(['status' => 'sent']);
        DB::table('invoices')->whereIn('status', ['void', 'written_off'])->update(['status' => 'cancelled']);

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $this->assertOnlyLatestValues($table, $column, $definition['values']);
                $this->alter($table, $column, $definition, 'ENUM('.$this->quoted($definition['values']).')');
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->columns, true) as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $this->alter($table, $column, $definition, 'VARCHAR(255)');
            }
        }
    }

    /** @param array<int, string> $values */
    private function assertOnlyLatestValues(string $table, string $column, array $values): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $invalid = DB::table($table)->whereNotNull($column)->whereNotIn($column, $values)->distinct()->pluck($column)->all();

        if ($invalid !== []) {
            throw new RuntimeException("Cannot convert {$table}.{$column} to ENUM; unsupported values: ".implode(', ', $invalid));
        }
    }

    /** @param array{values: array<int, string>, default?: string} $definition */
    private function alter(string $table, string $column, array $definition, string $type): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $default = isset($definition['default']) ? " DEFAULT '".str_replace("'", "''", $definition['default'])."'" : '';
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} NOT NULL{$default}");
    }

    /** @param array<int, string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".str_replace("'", "''", $value)."'", $values));
    }
};
