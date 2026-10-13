<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->string('template', 100)->nullable()->after('prefix');
            $table->string('reset_period', 10)->default('never')->after('next_number');
            $table->string('period_key', 10)->nullable()->after('reset_period');
            $table->boolean('allow_manual')->default(false)->after('period_key');
        });

        // Each row keeps producing the codes it produces today: PREFIX-YYYY-NNNNN, or PREFIX-NNNNN without the year.
        DB::table('number_sequences')->orderBy('id')->each(function (object $row): void {
            DB::table('number_sequences')->where('id', $row->id)->update([
                'template' => '{PREFIX}-'.($row->year_suffix ? '{YYYY}-' : '').'{SEQ:'.max(1, min(10, (int) $row->padding)).'}',
            ]);
        });

        Schema::table('number_sequences', function (Blueprint $table) {
            $table->string('template', 100)->nullable(false)->change();
            $table->dropColumn(['padding', 'year_suffix']);
        });
    }

    /** Only the zero-padding and the year of a template survive a rollback; reset, manual input and other tokens do not. */
    public function down(): void
    {
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('padding')->default(5)->after('next_number');
            $table->boolean('year_suffix')->default(true)->after('padding');
        });

        DB::table('number_sequences')->orderBy('id')->each(function (object $row): void {
            DB::table('number_sequences')->where('id', $row->id)->update([
                'padding' => preg_match('/\{SEQ:(\d+)\}/', $row->template, $match) === 1 ? min(255, (int) $match[1]) : 5,
                'year_suffix' => preg_match('/\{YY(?:YY)?\}/', $row->template) === 1,
            ]);
        });

        Schema::table('number_sequences', function (Blueprint $table) {
            $table->dropColumn(['template', 'reset_period', 'period_key', 'allow_manual']);
        });
    }
};
