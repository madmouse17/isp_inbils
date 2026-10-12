<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RouterOS API provisioning gives way to FreeRADIUS: NAS rows become RADIUS clients (nasname, CoA port, shared
 * secret), network accounts lose their router binding and the router-side record. Data in the dropped columns is
 * lost; down() brings the columns back empty.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('nas_devices', function (Blueprint $table) {
            $table->string('nasname', 128)->nullable()->unique()->after('network_asset_id');
            $table->unsignedSmallInteger('coa_port')->default(1700)->after('nasname');
            $table->text('radius_secret')->nullable()->after('coa_port'); // encrypted cast: the ciphertext is longer than the secret
            $table->string('radius_nasname', 128)->nullable()->after('radius_secret'); // the nasname last written to RADIUS
            $table->timestamp('radius_synced_at')->nullable()->after('radius_nasname');
        });

        // The router address FreeRADIUS sees defaults to the asset's management IP; a duplicate stays empty (the unique key).
        $taken = [];
        $rows = DB::table('nas_devices')
            ->join('network_assets', 'network_assets.id', '=', 'nas_devices.network_asset_id')
            ->orderBy('nas_devices.id')
            ->get(['nas_devices.id', 'network_assets.management_ip']);

        foreach ($rows as $row) {
            $ip = (string) $row->management_ip;

            if ($ip !== '' && ! isset($taken[$ip])) {
                $taken[$ip] = true;
                DB::table('nas_devices')->where('id', $row->id)->update(['nasname' => $ip]);
            }
        }

        Schema::table('nas_devices', function (Blueprint $table) {
            $table->dropColumn([
                'api_protocol', 'api_port', 'api_username', 'api_password', 'isolir_profile', 'provisioning_enabled',
                'routeros_version', 'last_checked_at', 'last_seen_at', 'unreachable_since', 'unmanaged_count', 'unmanaged_usernames',
            ]);
        });

        Schema::table('network_accounts', function (Blueprint $table) {
            // The company_id foreign key needs an index at every step: the new one exists before the old ones go.
            $table->index(['company_id', 'sync_status']);
            $table->dropForeign(['nas_device_id']);
            $table->dropForeign(['provisioned_nas_device_id']);
            $table->dropUnique(['company_id', 'username']);
            $table->dropIndex(['company_id', 'nas_device_id', 'sync_status']);
        });

        Schema::table('network_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'nas_device_id', 'provisioned_nas_device_id', 'provisioned_profile', 'provisioned_service_type',
                'last_online_at', 'current_address',
            ]);
            $table->string('provisioned_group', 64)->nullable()->after('provisioned_username');
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            $table->foreignId('nas_device_id')->nullable()->after('subscription_id')->constrained('nas_devices')->restrictOnDelete();
            $table->foreignId('provisioned_nas_device_id')->nullable()->after('password')->constrained('nas_devices')->restrictOnDelete();
            $table->string('provisioned_profile', 64)->nullable()->after('provisioned_username');
            $table->enum('provisioned_service_type', ['pppoe', 'hotspot'])->nullable()->after('provisioned_profile');
            $table->timestamp('last_online_at')->nullable();
            $table->string('current_address', 45)->nullable();
            $table->unique(['company_id', 'username']);
            $table->index(['company_id', 'nas_device_id', 'sync_status']);
            $table->dropIndex(['company_id', 'sync_status']);
            $table->dropUnique(['username']);
            $table->dropColumn('provisioned_group');
        });

        Schema::table('nas_devices', function (Blueprint $table) {
            $table->enum('api_protocol', ['rest', 'binary'])->default('rest')->after('network_asset_id');
            $table->unsignedSmallInteger('api_port')->default(443);
            $table->string('api_username', 64)->nullable();
            $table->text('api_password')->nullable();
            $table->string('isolir_profile', 64)->default('isolir');
            $table->boolean('provisioning_enabled')->default(false);
            $table->string('routeros_version', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('unreachable_since')->nullable();
            $table->unsignedInteger('unmanaged_count')->default(0);
            $table->json('unmanaged_usernames')->nullable();
        });

        Schema::table('nas_devices', function (Blueprint $table) {
            $table->dropColumn(['nasname', 'coa_port', 'radius_secret', 'radius_nasname', 'radius_synced_at']);
        });
    }
};
