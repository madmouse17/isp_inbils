<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // The table was never written by the application, so rows without a user or company are junk.
        DB::table('company_user')->whereNotExists(
            fn ($query) => $query->from('companies')->whereColumn('companies.id', 'company_user.company_id')
        )->orWhereNotExists(
            fn ($query) => $query->from('users')->whereColumn('users.id', 'company_user.user_id')
        )->delete();

        Schema::table('company_user', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // A user has at most one default membership: the unique index ignores the NULLs of the other rows.
            // VIRTUAL, not STORED: MySQL refuses a stored generated column over user_id, which has a CASCADE foreign key.
            $table->unsignedBigInteger('default_user_id')->nullable()->virtualAs('CASE WHEN is_default = 1 THEN user_id ELSE NULL END');
            $table->unique('default_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropUnique(['default_user_id']);
            $table->dropColumn('default_user_id');
            $table->dropForeign(['company_id']);
            $table->dropForeign(['user_id']);
        });
    }
};
