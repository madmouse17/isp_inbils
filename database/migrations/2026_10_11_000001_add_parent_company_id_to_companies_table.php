<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // One level only: a Head has no parent, a subsidiary points at its Head. Self-parenting and
            // depth are refused by CompanyHierarchyService; MySQL cannot CHECK against the auto-increment id.
            $table->foreignId('parent_company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_company_id');
        });
    }
};
