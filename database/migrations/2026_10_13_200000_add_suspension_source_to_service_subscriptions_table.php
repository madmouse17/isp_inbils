<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->enum('suspension_source', ['manual', 'overdue'])->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->dropColumn('suspension_source');
        });
    }
};
