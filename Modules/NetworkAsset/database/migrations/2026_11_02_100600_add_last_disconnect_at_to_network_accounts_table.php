<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * When the router last confirmed a Disconnect of this account's session. `pending_disconnect_at` is cleared once
     * every Disconnect succeeded, so without this column the page cannot say "Sesi diputus 00:22, menunggu router
     * menyambung ulang". NULL until a session was cut (absent is not "never online"). Added last, so MySQL 8.4 runs it
     * as ALGORITHM=INSTANT.
     */
    public function up(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('network_accounts', 'last_disconnect_at')) {
                $table->timestamp('last_disconnect_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('network_accounts', 'last_disconnect_at')) {
            Schema::table('network_accounts', fn (Blueprint $table) => $table->dropColumn('last_disconnect_at'));
        }
    }
};
