<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * `first_response_source` says what counted as the first response: a public staff reply ('reply') or the Mulai button
     * ('start'). `reopened_at` is when a customer reply last brought a resolved ticket back. `response_breached_notified_at`
     * is the once-only marker of the "response target missed" notice, next to the resolution and due markers.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'first_response_source')) {
                $table->string('first_response_source', 10)->nullable()->after('first_response_at');
            }

            if (! Schema::hasColumn('tickets', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('resolved_at');
            }

            if (! Schema::hasColumn('tickets', 'response_breached_notified_at')) {
                $table->timestamp('response_breached_notified_at')->nullable()->after('response_due_notified_at');
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            ['first_response_source', 'reopened_at', 'response_breached_notified_at'],
            fn (string $column) => Schema::hasColumn('tickets', $column),
        ));

        if ($present !== []) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
