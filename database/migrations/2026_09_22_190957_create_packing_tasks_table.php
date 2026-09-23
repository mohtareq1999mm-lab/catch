<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('packing_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained('fulfillments')->onDelete('cascade');
            $table->foreignId('packing_station_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->string('status', 30)->default('pending'); // pending, assigned, packing, packed, verified, cancelled
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->decimal('weight', 10, 2)->nullable(); // in kg
            $table->json('dimensions')->nullable(); // length, width, height in cm
            $table->json('package_materials')->nullable(); // box type, padding materials
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['fulfillment_id', 'status']);
            $table->index(['packing_station_id', 'status']);
            $table->index('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packing_tasks');
    }
};
