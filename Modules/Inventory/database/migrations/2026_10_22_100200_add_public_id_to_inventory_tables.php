<?php

use App\Support\Database\PublicIdColumn;
use Illuminate\Database\Migrations\Migration;

/** Opaque ids: see 2026_10_22_100000_add_public_id_to_core_tables. */
return new class() extends Migration
{
    private const TABLES = [
        'categories',
        'units',
        'products',
        'stocks',
        'stock_movements',
        'stock_adjustment_requests',
        'inventory_requests',
        'inventory_request_items',
        'material_return_cases',
        'material_return_events',
        'material_return_lines',
    ];

    public function up(): void
    {
        PublicIdColumn::add(self::TABLES);
    }

    public function down(): void
    {
        PublicIdColumn::drop(self::TABLES);
    }
};
