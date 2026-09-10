<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3 — license key pool (locked decision A2).
     *
     * Keys are only ever stored ENCRYPTED (encrypted_key). Lifecycle states:
     * available → assigned → consumed; revocation from any state. The
     * business transitions are enforced by a later service workstream —
     * this migration provides the representation only.
     *
     * allocated_entitlement_id uses ON DELETE SET NULL: deleting an
     * entitlement must never destroy purchased license inventory; it merely
     * releases the allocation pointer.
     */
    public function up(): void
    {
        Schema::create('digital_license_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->string('status', 20)->default('available');
            $table->foreignId('allocated_entitlement_id')
                ->nullable()
                ->constrained('digital_entitlements')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Pool lookup ("next available key for asset X") doubles as the
            // MySQL FK index for asset_id.
            $table->index(['asset_id', 'status'], 'digital_license_keys_asset_status_idx');
            $table->index('allocated_entitlement_id', 'digital_license_keys_allocation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_license_keys');
    }
};
