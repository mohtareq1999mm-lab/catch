<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('correction_to_id')->nullable()->constrained('invoices')->nullOnDelete();

            // Invoice Numbering
            $table->string('invoice_number', 50);
            $table->string('invoice_series', 10)->default('INV');
            $table->bigInteger('sequence_number')->unsigned();
            $table->year('sequence_year');

            // Financial Summary (structured, indexed)
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('shipping_price', 10, 3)->default(0);
            $table->decimal('coupon_discount', 10, 3)->default(0);
            $table->decimal('promotion_discount', 10, 3)->default(0);
            $table->decimal('total_discount', 10, 3)->default(0);
            $table->decimal('total', 10, 3)->default(0);
            $table->decimal('amount_paid', 10, 3)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_gateway', 50)->nullable();

            // Lifecycle Status
            $table->string('status', 20)->default('generated');

            // Immutable Snapshot
            $table->json('data');
            $table->string('snapshot_hash', 64)->nullable();

            // PDF Document Tracking
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('pdf_regenerated_at')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->string('pdf_checksum', 64)->nullable();
            $table->tinyInteger('generation_attempts')->unsigned()->default(0);
            $table->text('last_generation_error')->nullable();

            // Corrections
            $table->boolean('is_correction')->default(false);
            $table->string('correction_reason', 500)->nullable();
            $table->timestamp('corrected_at')->nullable();

            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            // Audit Metadata
            $table->timestamp('generated_at')->useCurrent();
            $table->string('generated_by', 50)->nullable()->default('system');
            $table->timestamps();

            // Unique Constraints
            $table->unique('order_id', 'uq_invoices_order_id');
            $table->unique('invoice_number', 'uq_invoices_invoice_number');

            // Indexes
            $table->index('user_id', 'idx_invoices_user_id');
            $table->index('status', 'idx_invoices_status');
            $table->index('currency', 'idx_invoices_currency');
            $table->index('payment_method', 'idx_invoices_payment_method');
            $table->index('payment_gateway', 'idx_invoices_payment_gateway');
            $table->index('generated_at', 'idx_invoices_generated_at');
            $table->index('total', 'idx_invoices_total');
            $table->index('sequence_year', 'idx_invoices_sequence_year');
            $table->index('transaction_id', 'idx_invoices_transaction_id');
            $table->index('correction_to_id', 'idx_invoices_correction_to_id');
            $table->index('snapshot_hash', 'idx_invoices_snapshot_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
