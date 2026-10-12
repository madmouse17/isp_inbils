<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('nas_devices', function (Blueprint $table) {
            // rest = RouterOS 7.1+ REST over HTTPS; binary = the API service (RouterOS 6 and 7) on 8728 plain or 8729 TLS.
            $table->enum('api_protocol', ['rest', 'binary'])->default('rest')->after('network_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('nas_devices', function (Blueprint $table) {
            $table->dropColumn('api_protocol');
        });
    }
};
