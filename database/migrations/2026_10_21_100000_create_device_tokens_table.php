<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * FCM registration tokens of the two mobile apps. A row belongs to the Sanctum token that registered it: every
     * way an app token disappears (logout, revocation, expiry prune) deletes the row with it, which silences pushes
     * to that install. `token` is unique through its hash because a registration token can be long enough to
     * exceed what a MySQL unique index may hold.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->enum('app', ['customer', 'technician']);
            $table->string('platform', 16)->default('android');
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('device_name', 100)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
