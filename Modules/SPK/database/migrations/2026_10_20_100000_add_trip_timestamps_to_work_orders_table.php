<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * "Berangkat" (the technician left for the customer) and the first finish of the work are timestamps inside the
     * existing statuses, not statuses of their own. SPKs that were already past those moments keep them empty.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('en_route_at')->nullable()->after('started_at');
            $table->timestamp('submitted_at')->nullable()->after('en_route_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['en_route_at', 'submitted_at']);
        });
    }
};
