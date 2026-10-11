<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Derived customer status (rolled up from subscriptions by CustomerStatusRecalculator).
     * The backfill mirrors that rule over non-deleted subscriptions; down() only drops the column.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('customer_status', ['prospective', 'active', 'inactive', 'suspended', 'terminated'])
                ->default('prospective')
                ->after('is_active');
            $table->index(['company_id', 'customer_status']);
        });

        $subs = 'SELECT 1 FROM service_subscriptions s WHERE s.customer_id = customers.id AND s.deleted_at IS NULL';

        DB::statement("
            UPDATE customers SET customer_status = CASE
                WHEN EXISTS ({$subs} AND s.status = 'active') THEN 'active'
                WHEN EXISTS ({$subs} AND s.status = 'suspended') THEN 'suspended'
                WHEN NOT EXISTS ({$subs} AND s.status <> 'pending') THEN 'prospective'
                WHEN NOT EXISTS ({$subs} AND s.status <> 'terminated') THEN 'terminated'
                ELSE 'inactive'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'customer_status']);
            $table->dropColumn('customer_status');
        });
    }
};
