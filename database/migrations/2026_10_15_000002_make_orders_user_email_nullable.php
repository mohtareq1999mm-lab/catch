<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone-only orders carry NULL user_email by design.
 *
 * VERIFIED FACTS:
 * - App\Services\Checkout\OrderCreationService writes
 *   'user_email' => $orderData['user_email'] ?? null (create + update paths).
 * - PhoneOnlyE2ETest::phone_only_user_can_checkout_cod... asserts an order
 *   with null email returns 200; live schema rejected it (SQLSTATE 23000/1048).
 * - information_schema (catch + catch_verify): orders.user_email NOT NULL,
 *   no default. No code path requires non-null email (invoices fall back).
 *
 * Widening only (NOT NULL → nullable). Safe on empty tables; reversible via
 * rollback while no NULL rows exist. user_phone untouched: no evidence of a
 * null-phone requirement (minimal change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('user_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('user_email')->nullable(false)->change();
        });
    }
};
