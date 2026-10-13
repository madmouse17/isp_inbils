<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings two permission names under the `<area>.<action>` grouping the role editor and sidebar use.
 * Only the `name` changes, so every role and user grant keeps its permission id.
 */
return new class() extends Migration
{
    private const RENAMES = [
        'user.two_factor.reset' => 'users.two_factor.reset',
        'document.export' => 'system.document.export',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /** @param  array<string, string>  $renames  old name => new name */
    private function rename(array $renames): void
    {
        $table = config('permission.table_names.permissions');

        DB::transaction(function () use ($table, $renames): void {
            foreach ($renames as $from => $to) {
                $source = DB::table($table)->where('guard_name', 'web')->where('name', $from)->first();

                if ($source === null) {
                    continue;
                }

                if (DB::table($table)->where('guard_name', 'web')->where('name', $to)->exists()) {
                    throw new RuntimeException("Cannot rename permission '{$from}': '{$to}' already exists. Merge the two rows by hand first.");
                }

                DB::table($table)->where('id', $source->id)->update(['name' => $to, 'updated_at' => now()]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
