<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * The opt-in to the daily warehouse digest mail (`inventory:send-digest`): off for everyone until the person switches
     * it on in their profile. One flag per user, for every company they work in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('inventory_digest')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inventory_digest');
        });
    }
};
