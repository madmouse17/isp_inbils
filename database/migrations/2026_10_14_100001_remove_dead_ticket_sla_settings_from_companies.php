<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    private const KEYS = ['ticket_default_sla_hours', 'ticket_sla_mode'];

    /** Nothing read these two keys: deadlines come from the SLA tier or the ticket category. */
    public function up(): void
    {
        DB::table('companies')->whereNotNull('settings')->orderBy('id')->each(function (object $company): void {
            $settings = json_decode((string) $company->settings, true);

            if (! is_array($settings) || array_intersect_key($settings, array_flip(self::KEYS)) === []) {
                return;
            }

            DB::table('companies')->where('id', $company->id)->update([
                'settings' => json_encode(array_diff_key($settings, array_flip(self::KEYS))),
            ]);
        });
    }

    /** Irreversible by design: the removed values were the same defaults for every company, and nothing used them. */
    public function down(): void {}
};
