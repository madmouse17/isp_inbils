<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'logo')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }

        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('work_order_evidence');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'logo')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('logo')->nullable()->after('code');
            });
        }

        if (! Schema::hasTable('ticket_attachments')) {
            Schema::create('ticket_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
                $table->foreignId('comment_id')->nullable()->constrained('ticket_comments')->nullOnDelete();
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedInteger('size_bytes')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_evidence')) {
            Schema::create('work_order_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
                $table->string('type')->default('photo');
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedInteger('size_bytes')->nullable();
                $table->string('caption')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
