<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const COLUMN = 'active_recurring_period';

    private const INDEX = 'invoices_active_recurring_period_unique';

    /**
     * One active recurring invoice per subscription and billing period: the billing run checks first, this key is the
     * backstop when two runs race. Only a live recurring invoice carries the period, so a cancelled or deleted one, a
     * manual invoice and an invoice without a period never collide (a NULL is never equal to another NULL in a unique
     * key). The column is generated, as payments.active_reference is: MySQL fills it for every existing row and it can
     * never drift from `status`.
     *
     * Each step looks at what is already there, so a run that stopped half way is repaired by running it again.
     */
    public function up(): void
    {
        // Read-only, and before any DDL: when two active invoices share a period nothing has changed yet.
        $duplicates = DB::table('invoices')
            ->selectRaw('company_id, subscription_id, billing_period_start, GROUP_CONCAT(number ORDER BY id) AS numbers')
            ->where('type', 'recurring')
            ->where('status', '<>', 'cancelled')
            ->whereNull('deleted_at')
            ->whereNotNull('subscription_id')
            ->whereNotNull('billing_period_start')
            ->groupBy('company_id', 'subscription_id', 'billing_period_start')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        throw_if(
            $duplicates->isNotEmpty(),
            RuntimeException::class,
            'Tagihan berulang aktif ganda per periode harus dibereskan dulu: '.$duplicates->pluck('numbers')->implode('; '),
        );

        if (! Schema::hasColumn('invoices', self::COLUMN)) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->date(self::COLUMN)->nullable()->storedAs("case when `type` = 'recurring' and `status` <> 'cancelled' and `deleted_at` is null then `billing_period_start` end");
            });
        }

        if (! Schema::hasIndex('invoices', self::INDEX)) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->unique(['company_id', 'subscription_id', self::COLUMN], self::INDEX);
            });
        }
    }

    /** The key goes first: dropping the column alone would leave a unique key on the two columns that remain. */
    public function down(): void
    {
        if (Schema::hasIndex('invoices', self::INDEX)) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        }

        if (Schema::hasColumn('invoices', self::COLUMN)) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(self::COLUMN));
        }
    }
};
