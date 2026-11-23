<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('shipment_assignment_id')->nullable()->constrained('shipment_assignments')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->timestamp('attempted_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'attempt_number'], 'shipment_attempts_shipment_number_unique');
            $table->index(['shipment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_delivery_attempts');
    }
};
