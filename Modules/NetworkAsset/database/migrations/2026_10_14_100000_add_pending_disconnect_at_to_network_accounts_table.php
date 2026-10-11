<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            // Set before a router write that needs the subscriber's session dropped, cleared once every drop succeeded.
            $table->timestamp('pending_disconnect_at')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            $table->dropColumn('pending_disconnect_at');
        });
    }
};
