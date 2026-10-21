<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * A role belongs to one company (ADR-004). `company_id` is nullable until the cutover migration has copied the
     * global roles into the companies and the last migration makes it required; `template_key` records which template
     * the role was copied from. A name is unique inside a company, and a company has at most one copy of a template.
     * NULLs are distinct in a unique index, so the global roles that exist until the cutover do not collide.
     *
     * The composite unique indexes also serve as the index of the foreign key. The table holds a few hundred rows.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('template_key', 32)->nullable();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['company_id', 'name', 'guard_name']);
            $table->unique(['company_id', 'template_key']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    /**
     * The old unique index is valid again only once every role is global and its name unique: after the cutover
     * migration's own down().
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'name', 'guard_name']);
            $table->dropUnique(['company_id', 'template_key']);
            $table->unique(['name', 'guard_name']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'template_key']);
        });
    }
};
