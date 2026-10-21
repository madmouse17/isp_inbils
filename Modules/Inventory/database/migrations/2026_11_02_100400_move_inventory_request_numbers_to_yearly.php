<?php

use App\Support\NumberSequences\ResetPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    private const ENTITY = 'inventory_request';

    /** What every company started with: REQ-20261020-0003, a counter that restarts each day. */
    private const OLD_TEMPLATE = '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}';

    /** Like the SPK and ticket codes: REQ-2026-00003, a counter that restarts each year (K29). */
    private const NEW_TEMPLATE = '{PREFIX}-{YYYY}-{SEQ:5}';

    /**
     * Moves the companies that still use the first format to the yearly one. A company that changed its template or
     * its reset period on the Penomoran page is left alone, and so is its counter. Codes already issued keep their
     * text: the two shapes cannot collide, and the service skips a code that is taken anyway.
     */
    public function up(): void
    {
        $this->move(self::OLD_TEMPLATE, ResetPeriod::Daily, self::NEW_TEMPLATE, ResetPeriod::Yearly);
    }

    /** Back to the daily format for the rows that still hold the new pair, which is what up() wrote. */
    public function down(): void
    {
        $this->move(self::NEW_TEMPLATE, ResetPeriod::Yearly, self::OLD_TEMPLATE, ResetPeriod::Daily);
    }

    /** `period_key` null and the counter at 1: the next code opens the period it is made in and joins the new sequence. */
    private function move(string $fromTemplate, ResetPeriod $fromReset, string $toTemplate, ResetPeriod $toReset): void
    {
        DB::table('number_sequences')
            ->where('entity_type', self::ENTITY)
            ->where('template', $fromTemplate)
            ->where('reset_period', $fromReset->value)
            ->update([
                'template' => $toTemplate,
                'reset_period' => $toReset->value,
                'period_key' => null,
                'next_number' => 1,
                'updated_at' => now(),
            ]);
    }
};
