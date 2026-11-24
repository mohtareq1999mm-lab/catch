<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32)->nullable();
            $table->string('key_version', 32)->nullable();
            $table->string('fingerprint_hash', 64)->nullable();
            $table->json('candidate_recipient_ids');
            $table->string('status', 32)->default('open'); // open|linked|split|dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_identity_conflicts');
    }
};
