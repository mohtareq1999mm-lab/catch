<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10: packages belong to fulfillments (order_id denormalized).
     * Invariant SUM(package_items.quantity) <= fulfillment_item.quantity_picked
     * is enforced transactionally in PackingService (a unique constraint alone
     * cannot express it).
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained('fulfillments')->onDelete('cascade');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('packing_task_id')->nullable()->constrained('packing_tasks')->onDelete('set null');
            $table->string('package_number', 50)->unique();
            $table->string('barcode', 100)->unique()->nullable();
            // open → sealed → handed_off, plus voided (supervisor-only reopen).
            $table->string('status', 20)->default('open');
            $table->decimal('weight', 10, 2)->nullable();
            $table->json('dimensions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamps();

            $table->index(['fulfillment_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->onDelete('cascade');
            $table->foreignId('fulfillment_item_id')->constrained('fulfillment_items')->onDelete('restrict');
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->timestamps();

            $table->unique(['package_id', 'fulfillment_item_id']);
            $table->index(['fulfillment_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};
