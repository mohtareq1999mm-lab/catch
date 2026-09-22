<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade'); // Denormalized for perf
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reserved_quantity', 15, 2)->default(0); // Optional: location-specific reservation
            $table->timestamps();
            
            $table->unique(['product_id', 'location_id']);
            $table->index(['warehouse_id', 'product_id']);
            $table->index(['location_id', 'quantity']);
        });
        
        // Add check constraints - SQLite uses inline checks, MySQL uses ALTER TABLE
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE product_locations ADD CONSTRAINT check_quantity_non_negative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE product_locations ADD CONSTRAINT check_reserved_non_negative CHECK (reserved_quantity >= 0)');
            DB::statement('ALTER TABLE product_locations ADD CONSTRAINT check_reserved_lte_quantity CHECK (reserved_quantity <= quantity)');
        }
        // SQLite check constraints must be added in table definition, skipping for existing table
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_locations');
    }
};
