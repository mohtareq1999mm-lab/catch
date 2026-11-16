<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration for mobile Bearer credential support (S3 / D9).
 *
 * Native mobile clients authenticate with an opaque `Authorization: Bearer`
 * token instead of the browser's HttpOnly cookie. Only a domain-separated
 * HMAC hash of the token is stored (`mobile_token_hash`); the raw token is
 * returned to the client exactly once and never persisted or logged.
 *
 * Scope constraints:
 *  - Additive only: nullable column, no backfill, no data migration.
 *  - No new table: mobile credential lives on the SAME `guest_identities`
 *    row so cart/checkout/merge business logic stays shared (D9).
 *  - MySQL permits many NULLs in a unique index, so cookie-only identities
 *    are unaffected; a non-null hash maps to exactly one identity.
 *
 * Rollback (S3): drops the unique index, then the column. Browser cookie
 * credentials are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('guest_identities', 'mobile_token_hash')) {
            return;
        }

        Schema::table('guest_identities', function (Blueprint $table) {
            $table->char('mobile_token_hash', 64)->nullable()->after('token_hash');
            $table->unique('mobile_token_hash', 'uq_guest_identities_mobile_token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('guest_identities', 'mobile_token_hash')) {
            return;
        }

        Schema::table('guest_identities', function (Blueprint $table) {
            $table->dropUnique('uq_guest_identities_mobile_token');
            $table->dropColumn('mobile_token_hash');
        });
    }
};
