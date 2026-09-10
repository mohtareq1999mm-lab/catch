<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('debit_note_number', 50)->unique();
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

            $table->index('invoice_id', 'idx_dn_invoice_id');
            $table->index('type', 'idx_dn_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
