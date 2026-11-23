<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_labels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_mode', 16)->nullable();
            $table->string('format', 16);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');
            $table->string('path', 512);
            $table->char('sha256', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'created_at']);
            $table->index(['provider_code', 'provider_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_labels');
    }
};
