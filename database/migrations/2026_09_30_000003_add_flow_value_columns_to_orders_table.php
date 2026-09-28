<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level business copies of Flow Input values.
 *
 * Ownership decision (verified against schema 2026-09-28):
 * - from_country / to_country -> orders.origin_country_id /
 *   orders.destination_country_id (FK countries, queryable, constrained).
 * - customs_reference -> orders.customs_reference (nullable string; the
 *   shipment may not exist yet at transition time, so shipment metadata
 *   cannot be the primary store).
 * - governorate / address / pickup_location / warehouse keep their
 *   EXISTING columns (orders.governorate_id/address/pickup_location_*,
 *   fulfillments.warehouse_id) — no duplication.
 *
 * All columns nullable/additive: existing rows and clients unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'origin_country_id')) {
                $table->foreignId('origin_country_id')->nullable()->after('governorate_id')->constrained('countries')->restrictOnDelete();
            }
            if (!Schema::hasColumn('orders', 'destination_country_id')) {
                $table->foreignId('destination_country_id')->nullable()->after('origin_country_id')->constrained('countries')->restrictOnDelete();
            }
            if (!Schema::hasColumn('orders', 'customs_reference')) {
                $table->string('customs_reference', 100)->nullable()->after('destination_country_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropConstrainedForeignId('destination_country_id');
            } catch (\Throwable) {
                try {
                    $table->dropColumn('destination_country_id');
                } catch (\Throwable) {
                }
            }
            try {
                $table->dropConstrainedForeignId('origin_country_id');
            } catch (\Throwable) {
                try {
                    $table->dropColumn('origin_country_id');
                } catch (\Throwable) {
                }
            }
            try {
                $table->dropColumn('customs_reference');
            } catch (\Throwable) {
            }
        });
    }
};
