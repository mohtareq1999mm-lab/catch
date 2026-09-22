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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->string('code', 50); // A-01, B-02, etc.
            $table->string('name');
            $table->string('type', 30)->nullable(); // shelf, bin, zone
            $table->string('status', 20)->default('active');
            $table->integer('priority')->default(0); // For picking order
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
