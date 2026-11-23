<?php

use App\Enums\Shipping\ShipmentExceptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #151: internal exceptions for provider events that cannot be mapped safely.
 * Creating or resolving one never rewrites shipment history or invents a
 * transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('provider_event_id')->nullable()->constrained('shipping_provider_events')->nullOnDelete();
            $table->string('type', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default(ShipmentExceptionStatus::OPEN->value);
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('provider_event_id', 'uq_shipment_exception_provider_event');
            $table->index(['shipment_id', 'status'], 'idx_shipment_exception_shipment');
            $table->index(['status', 'opened_at'], 'idx_shipment_exception_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_exceptions');
    }
};
