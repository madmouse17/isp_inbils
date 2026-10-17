<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // The record of a tenant data export: platform metadata, not tenant data, so it has no tenant scope. The
        // company key restricts, so only the purge command (which removes a tenant's export rows with the tenant)
        // can delete a company that has exports. The archive lives on the private disk (platform/exports/{uuid}.zip);
        // the row survives its cleanup as `expired`, which is the evidence a deletion request needs.
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('requested_by_platform_admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('file_path', 1024)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('row_counts')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
    }
};
