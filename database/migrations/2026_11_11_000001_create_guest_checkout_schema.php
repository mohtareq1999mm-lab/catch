<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest Checkout schema (additive, nullable-FK only).
 *
 * Ownership model:
 *  - guest_identities is the server-managed guest credential (hash only).
 *  - carts.guest_id owns exactly one active guest cart (UNIQUE, NULLs allowed
 *    so authenticated carts are untouched).
 *  - orders.guest_id links guest orders for audit/tracking/cancellation;
 *    orders.user_id stays NULL (no fabricated customer accounts).
 *  - Token hashes for public tracking/cancellation are stored 1-way; the raw
 *    tokens are never persisted.
 *  - guest_idempotency_keys is the durable anti-double-submit guard scoped to
 *    (guest, operation, key).
 *
 * Rollback: all guest tables/columns disappear; legacy carts/orders untouched.
 * Never run against production without an approved deployment window.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (!Schema::hasTable('guest_identities')) {
            Schema::create('guest_identities', function (Blueprint $table) {
                $table->id();
                // SHA-256 HMAC of the raw browser token. Raw value never stored.
                $table->char('token_hash', 64)->unique('uq_guest_identities_token_hash');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index('expires_at', 'idx_guest_identities_expires_at');
            });
        }

        if (!Schema::hasTable('guest_idempotency_keys')) {
            Schema::create('guest_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guest_id')
                    ->constrained('guest_identities')
                    ->cascadeOnDelete();
                $table->string('scope', 40);
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->string('status', 20)->default('processing');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->json('response')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['guest_id', 'scope', 'idempotency_key'],
                    'uq_guest_idempotency_scope_key'
                );
                $table->index('expires_at', 'idx_guest_idempotency_expires_at');
                $table->index('order_id', 'idx_guest_idempotency_order_id');
            });
        }

        // ── carts: guest owner + retention deadline ──────────────────────
        if (!Schema::hasColumn('carts', 'guest_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->unsignedBigInteger('guest_id')->nullable()->after('user_id');
                $table->foreign('guest_id')
                    ->references('id')
                    ->on('guest_identities')
                    ->nullOnDelete();
                // One cart per guest. MySQL/PostgreSQL allow many NULLs, so
                // authenticated carts keep their existing UNIQUE(user_id).
                $table->unique('guest_id', 'uq_carts_guest_id');
                $table->index(['guest_id', 'status'], 'idx_carts_guest_status');
            });
        }

        if (!Schema::hasColumn('carts', 'guest_expires_at')) {
            Schema::table('carts', function (Blueprint $table) {
                // Retention deadline for guest carts ONLY (7 days of inactivity
                // by default). The legacy expires_at column stays the
                // abandoned-cart activity window for authenticated carts.
                $table->timestamp('guest_expires_at')->nullable()->after('expires_at');
                $table->index('guest_expires_at', 'idx_carts_guest_expires_at');
            });
        }

        // ── orders: guest link + 1-way tracking/cancellation credentials ──
        if (!Schema::hasColumn('orders', 'guest_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('guest_id')->nullable()->after('user_id');
                $table->foreign('guest_id')
                    ->references('id')
                    ->on('guest_identities')
                    ->nullOnDelete();
                $table->index(['guest_id', 'created_at'], 'idx_orders_guest_created');
            });
        }

        if (!Schema::hasColumn('orders', 'guest_tracking_token_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->char('guest_tracking_token_hash', 64)->nullable()->unique('uq_orders_guest_tracking_token');
                $table->timestamp('guest_tracking_revoked_at')->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'guest_cancellation_token_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->char('guest_cancellation_token_hash', 64)->nullable()->unique('uq_orders_guest_cancel_token');
                $table->timestamp('guest_cancellation_token_used_at')->nullable();
                $table->timestamp('guest_cancellation_token_expires_at')->nullable();
            });
        }

        // One pending order per guest (mirrors idx_orders_user_pending_unique).
        if ($driver === 'mysql') {
            if (!Schema::hasColumn('orders', 'pending_guest_id')) {
                DB::statement("
                    ALTER TABLE `orders`
                    ADD COLUMN `pending_guest_id` BIGINT UNSIGNED
                    AS (CASE WHEN `status` = 'pending' THEN `guest_id` ELSE NULL END) VIRTUAL
                ");
            }

            $exists = collect(DB::select(
                "SHOW INDEX FROM `orders` WHERE Key_name = 'idx_orders_guest_pending_unique'"
            ))->isNotEmpty();

            if (!$exists) {
                DB::statement('CREATE UNIQUE INDEX `idx_orders_guest_pending_unique` ON `orders` (`pending_guest_id`)');
            }
        } else {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_guest_pending_unique
                ON orders(guest_id)
                WHERE status = 'pending'
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $exists = collect(DB::select(
                "SHOW INDEX FROM `orders` WHERE Key_name = 'idx_orders_guest_pending_unique'"
            ))->isNotEmpty();
            if ($exists) {
                DB::statement('DROP INDEX `idx_orders_guest_pending_unique` ON `orders`');
            }
            if (Schema::hasColumn('orders', 'pending_guest_id')) {
                DB::statement('ALTER TABLE `orders` DROP COLUMN `pending_guest_id`');
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_orders_guest_pending_unique');
        }

        if (Schema::hasColumn('orders', 'guest_cancellation_token_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('uq_orders_guest_cancel_token');
                $table->dropColumn([
                    'guest_cancellation_token_hash',
                    'guest_cancellation_token_used_at',
                    'guest_cancellation_token_expires_at',
                ]);
            });
        }

        if (Schema::hasColumn('orders', 'guest_tracking_token_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('uq_orders_guest_tracking_token');
                $table->dropColumn(['guest_tracking_token_hash', 'guest_tracking_revoked_at']);
            });
        }

        if (Schema::hasColumn('orders', 'guest_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['guest_id']);
                $table->dropIndex('idx_orders_guest_created');
                $table->dropColumn('guest_id');
            });
        }

        if (Schema::hasColumn('carts', 'guest_expires_at')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropIndex('idx_carts_guest_expires_at');
                $table->dropColumn('guest_expires_at');
            });
        }

        if (Schema::hasColumn('carts', 'guest_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign(['guest_id']);
                $table->dropUnique('uq_carts_guest_id');
                $table->dropIndex('idx_carts_guest_status');
                $table->dropColumn('guest_id');
            });
        }

        Schema::dropIfExists('guest_idempotency_keys');
        Schema::dropIfExists('guest_identities');
    }
};
