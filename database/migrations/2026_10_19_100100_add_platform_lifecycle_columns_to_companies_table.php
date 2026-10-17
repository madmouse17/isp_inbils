<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Written only by the platform lifecycle (TenantLifecycleService). suspended_at proves the 30-day deletion
            // gate and tells a platform suspension from a company that was inactive before; neither is mass assignable.
            $table->timestamp('suspended_at')->nullable()->index();
            $table->timestamp('deletion_requested_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['suspended_at']);
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn(['suspended_at', 'deletion_requested_at']);
        });
    }
};
