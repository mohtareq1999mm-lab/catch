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
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('fulfillment_id')->nullable()->after('order_id')->constrained('fulfillments')->onDelete('set null');
            $table->foreignId('packing_task_id')->nullable()->after('fulfillment_id')->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['fulfillment_id']);
            $table->dropForeign(['packing_task_id']);
            $table->dropColumn(['fulfillment_id', 'packing_task_id']);
        });
    }
};
