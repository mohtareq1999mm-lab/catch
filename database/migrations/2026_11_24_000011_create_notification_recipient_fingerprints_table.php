<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipient_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->string('key_version', 32);
            $table->string('fingerprint_hash', 64);
            $table->timestamps();

            $table->unique(['key_version', 'fingerprint_hash'], 'nrf_key_version_hash_unique');
            $table->unique(['recipient_id', 'key_version'], 'nrf_recipient_key_version_unique');
            $table->index('recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipient_fingerprints');
    }
};
