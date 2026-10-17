<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // The operator identity of the /platform panel. A separate table, never `users`: a platform admin has no
        // company, no membership, no tenant role and no remember-me, so there is no remember_token column.
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            // Secret and recovery codes hold ciphertext (encrypted casts), hence text columns. A secret with a
            // null confirmed_at is an enrolment still waiting for its first valid code.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Last accepted TOTP time-step: a code at or before it is a replay and is refused.
            $table->unsignedBigInteger('two_factor_last_used_step')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
