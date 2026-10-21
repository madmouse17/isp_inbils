<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const COLUMNS = ['supplier_name', 'purchase_reference', 'unit_cost'];

    /**
     * Where a goods receipt came from (K30): the supplier, the invoice or delivery-note number, and the price of one unit.
     * Only a `receive` movement fills them; every other movement and every row from before stays NULL. The columns go at the
     * end of the table, so MySQL adds them in place (ALGORITHM=INSTANT). No index: they are read with a page of the ledger,
     * never searched or filtered by.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'supplier_name')) {
                $table->string('supplier_name', 150)->nullable();
            }

            if (! Schema::hasColumn('stock_movements', 'purchase_reference')) {
                $table->string('purchase_reference', 100)->nullable();
            }

            if (! Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn (string $column) => Schema::hasColumn('stock_movements', $column)));

        if ($present !== []) {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
