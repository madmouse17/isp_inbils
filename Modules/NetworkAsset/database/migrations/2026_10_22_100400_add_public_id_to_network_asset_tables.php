<?php

use App\Support\Database\PublicIdColumn;
use Illuminate\Database\Migrations\Migration;

/** Opaque ids: see 2026_10_22_100000_add_public_id_to_core_tables. */
return new class() extends Migration
{
    private const TABLES = [
        'nas_devices',
        'network_accounts',
        'network_asset_installations',
        'network_assets',
        'odn_ports',
        'odn_splitters',
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
