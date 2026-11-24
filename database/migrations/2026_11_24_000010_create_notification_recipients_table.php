<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_type', 32); // guest_contact|user
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guest_identities')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['recipient_type', 'status']);
            $table->index(['user_id', 'recipient_type']);
            $table->index(['guest_id', 'recipient_type']);
            $table->index(['guest_id', 'user_id', 'recipient_type'], 'nr_guest_user_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
