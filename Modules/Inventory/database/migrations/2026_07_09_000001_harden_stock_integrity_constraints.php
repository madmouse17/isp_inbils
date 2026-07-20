<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantity_non_negative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_reserved_non_negative CHECK (reserved_quantity >= 0)');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_reserved_not_above_quantity CHECK (reserved_quantity <= quantity)');
        DB::statement('CREATE UNIQUE INDEX stock_movements_reference_unique ON stock_movements (company_id, product_id, ((COALESCE(from_location_id, to_location_id))), movement_type, reference_type, reference_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP INDEX stock_movements_reference_unique');
        DB::statement('ALTER TABLE stocks DROP CHECK stocks_reserved_not_above_quantity');
        DB::statement('ALTER TABLE stocks DROP CHECK stocks_reserved_non_negative');
        DB::statement('ALTER TABLE stocks DROP CHECK stocks_quantity_non_negative');
    }
};
