<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only: a RADIUS username is `local@realm` (realm = the lower-cased company code), so existing accounts
 * without an `@` get their company's realm. A username that would not fit the 64-character column keeps its old name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $codes = DB::table('companies')->pluck('code', 'id');

        DB::table('network_accounts')->where('username', 'not like', '%@%')->orderBy('id')->each(function (object $account) use ($codes): void {
            $realm = strtolower((string) ($codes[$account->company_id] ?? ''));
            $username = $account->username.'@'.$realm;

            if ($realm !== '' && strlen($username) <= 64) {
                DB::table('network_accounts')->where('id', $account->id)->update(['username' => $username, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        $codes = DB::table('companies')->pluck('code', 'id');

        DB::table('network_accounts')->where('username', 'like', '%@%')->orderBy('id')->each(function (object $account) use ($codes): void {
            $suffix = '@'.strtolower((string) ($codes[$account->company_id] ?? ''));

            if ($suffix !== '@' && str_ends_with($account->username, $suffix)) {
                DB::table('network_accounts')->where('id', $account->id)->update(['username' => substr($account->username, 0, -strlen($suffix)), 'updated_at' => now()]);
            }
        });
    }
};
