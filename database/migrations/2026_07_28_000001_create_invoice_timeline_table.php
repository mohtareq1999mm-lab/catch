<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->nullableMorphs('actor');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('invoice_id', 'idx_timeline_invoice_id');
            $table->index('event', 'idx_timeline_event');
            $table->index('created_at', 'idx_timeline_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_timeline');
    }
};
