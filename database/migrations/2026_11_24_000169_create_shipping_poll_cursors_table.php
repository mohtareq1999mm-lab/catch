<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_poll_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 32);
            $table->string('mode', 16);
            $table->string('account_code', 64);
            $table->string('cursor', 191)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'mode', 'account_code'], 'uq_shipping_poll_cursor_identity');
            $table->index(['provider_code', 'mode'], 'idx_shipping_poll_cursor_provider_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_poll_cursors');
    }
};
