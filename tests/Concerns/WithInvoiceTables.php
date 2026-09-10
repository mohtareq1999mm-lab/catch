<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait WithInvoiceTables
{
    protected function createInvoiceTables(): void
    {
        if (!Schema::hasTable('invoice_sequences')) {
            Schema::create('invoice_sequences', function (Blueprint $table) {
                $table->string('series', 10);
                $table->year('sequence_year');
                $table->bigInteger('last_sequence')->unsigned()->default(0);
                $table->timestamps();
                $table->primary(['series', 'sequence_year']);
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('correction_to_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->string('invoice_number', 50);
                $table->string('invoice_series', 10)->default('INV');
                $table->bigInteger('sequence_number')->unsigned();
                $table->year('sequence_year');
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
                $table->string('status', 20)->default('generated');
                $table->json('data');
                $table->string('snapshot_hash', 64)->nullable();
                $table->string('verification_hash', 64)->nullable();
                $table->timestamp('pdf_generated_at')->nullable();
                $table->timestamp('pdf_regenerated_at')->nullable();
                $table->string('pdf_path', 500)->nullable();
                $table->string('pdf_checksum', 64)->nullable();
                $table->tinyInteger('generation_attempts')->unsigned()->default(0);
                $table->text('last_generation_error')->nullable();
                $table->boolean('is_correction')->default(false);
                $table->string('correction_reason', 500)->nullable();
                $table->timestamp('corrected_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason', 500)->nullable();
                $table->timestamp('generated_at')->useCurrent();
                $table->string('generated_by', 50)->nullable()->default('system');
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('downloaded_at')->nullable();
                $table->timestamp('printed_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->unsignedSmallInteger('verify_count')->default(0);
                $table->timestamps();
                $table->unique('order_id', 'uq_invoices_order_id');
                $table->unique('invoice_number', 'uq_invoices_invoice_number');
                $table->index('user_id', 'idx_invoices_user_id');
                $table->index('status', 'idx_invoices_status');
            });
        }

        if (!Schema::hasTable('invoice_timeline')) {
            Schema::create('invoice_timeline', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('event', 50);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('actor_type')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();
                $table->index('invoice_id');
                $table->index('event');
                $table->index(['actor_type', 'actor_id']);
            });
        }

        if (!Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('credit_note_number', 50);
                $table->string('credit_note_series', 10)->default('CN');
                $table->bigInteger('sequence_number')->unsigned();
                $table->year('sequence_year');
                $table->string('reason', 500);
                $table->string('type', 30)->default('refund');
                $table->decimal('amount', 10, 3)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('line_items')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamps();
                $table->unique('credit_note_number', 'uq_credit_notes_number');
                $table->index('invoice_id');
            });
        }

        if (!Schema::hasTable('debit_notes')) {
            Schema::create('debit_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('debit_note_number', 50);
                $table->string('debit_note_series', 10)->default('DN');
                $table->bigInteger('sequence_number')->unsigned();
                $table->year('sequence_year');
                $table->string('reason', 500);
                $table->string('type', 30)->default('adjustment');
                $table->decimal('amount', 10, 3)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('line_items')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamps();
                $table->unique('debit_note_number', 'uq_debit_notes_number');
                $table->index('invoice_id');
            });
        }

        if (!Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('tracking_number', 100)->nullable();
                $table->string('courier', 100)->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('shipping_method', 30)->nullable();
                $table->decimal('shipping_cost', 10, 2)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->json('origin_address')->nullable();
                $table->json('destination_address')->nullable();
                $table->json('items')->nullable();
                $table->decimal('total_weight', 10, 2)->nullable();
                $table->string('weight_unit', 10)->default('kg');
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('estimated_delivery_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('order_id');
                $table->index('status');
                $table->index('tracking_number');
            });
        }

        if (!Schema::hasTable('coupon_reservations')) {
            Schema::create('coupon_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('order_id')->constrained()->onDelete('cascade');
                $table->timestamp('reserved_at');
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index(['coupon_id', 'expires_at']);
                $table->unique(['order_id']);
            });
        }
    }
}
