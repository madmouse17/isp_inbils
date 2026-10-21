<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const INDEX = 'tickets_company_id_customer_rated_at_customer_rating_index';

    /**
     * The customer's own 1 to 5 rating of a finished ticket, with an optional comment (K14). `customer_rating` stays NULL
     * until the customer rates: an absent rating is not a zero, so an average skips it. The columns go at the end of the
     * table, so MySQL adds them in place and instantly. The index serves the satisfaction figure of a period (equality on
     * the company, a range on the rating date) and covers it, since the rating is the last column of the key.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'customer_rating')) {
                $table->unsignedTinyInteger('customer_rating')->nullable();
            }

            if (! Schema::hasColumn('tickets', 'customer_rating_comment')) {
                $table->text('customer_rating_comment')->nullable();
            }

            if (! Schema::hasColumn('tickets', 'customer_rated_at')) {
                $table->timestamp('customer_rated_at')->nullable();
            }
        });

        if (! Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating'])) {
            Schema::table('tickets', fn (Blueprint $table) => $table->index(['company_id', 'customer_rated_at', 'customer_rating'], self::INDEX));
        }
    }

    /** The index goes first: dropping a column alone would only shorten it. */
    public function down(): void
    {
        if (Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating'])) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }

        $present = array_values(array_filter(
            ['customer_rating', 'customer_rating_comment', 'customer_rated_at'],
            fn (string $column) => Schema::hasColumn('tickets', $column),
        ));

        if ($present !== []) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
