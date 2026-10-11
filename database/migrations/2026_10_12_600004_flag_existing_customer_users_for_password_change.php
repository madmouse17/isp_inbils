<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Customer accounts created before the random-password rollout may still use the customer's
     * phone number as password. The portal refuses them until the customer picks a new one.
     */
    public function up(): void
    {
        $this->setFlag(true);
    }

    public function down(): void
    {
        $this->setFlag(false);
    }

    private function setFlag(bool $value): void
    {
        $tables = config('permission.table_names');

        DB::table('users')
            ->whereIn('id', DB::table($tables['model_has_roles'])
                ->join($tables['roles'], $tables['roles'].'.id', '=', $tables['model_has_roles'].'.role_id')
                ->where($tables['roles'].'.name', 'customer')
                ->where($tables['model_has_roles'].'.model_type', User::class)
                ->select($tables['model_has_roles'].'.model_id'))
            ->update(['must_change_password' => $value]);
    }
};
