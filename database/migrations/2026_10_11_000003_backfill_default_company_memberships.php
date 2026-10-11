<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * One default membership per user, in the home company of users.company_id. Users without a home
     * company (the bootstrap user before the Setup Wizard) get none.
     */
    public function up(): void
    {
        DB::table('company_user')->insertUsing(
            ['company_id', 'user_id', 'is_default', 'created_at', 'updated_at'],
            DB::table('users')
                ->whereNotNull('company_id')
                ->whereNotExists(
                    fn ($query) => $query->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->whereColumn('company_user.company_id', 'users.company_id')
                )
                ->select('company_id', 'id', DB::raw('1'), DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP')),
        );
    }

    /**
     * Removes the default home memberships this migration created. Memberships to other companies
     * stay: they were granted on purpose.
     */
    public function down(): void
    {
        DB::table('company_user')
            ->where('is_default', true)
            ->whereExists(
                fn ($query) => $query->from('users')
                    ->whereColumn('users.id', 'company_user.user_id')
                    ->whereColumn('users.company_id', 'company_user.company_id')
            )
            ->delete();
    }
};
