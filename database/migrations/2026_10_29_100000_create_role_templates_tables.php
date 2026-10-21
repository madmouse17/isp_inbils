<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * The platform's starter roles (ADR-004), copied into a company once, when it is created. Platform-owned: no
     * company_id, so tenant export and purge leave them alone. `key` is what the copy's `roles.template_key` points
     * at, so it never changes. Additive: nothing existing changes, so it is safe on a live database.
     */
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 32)->unique();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('role_template_has_permissions', function (Blueprint $table) {
            $table->foreignId('role_template_id')->constrained('role_templates')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_template_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_template_has_permissions');
        Schema::dropIfExists('role_templates');
    }
};
