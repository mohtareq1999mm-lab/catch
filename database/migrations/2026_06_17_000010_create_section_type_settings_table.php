<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_type_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_type_id')->constrained('section_types')->cascadeOnDelete();
            $table->string('setting_key', 50);
            $table->json('value');
            $table->timestamps();
            $table->unique(['section_type_id', 'setting_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_type_settings');
    }
};
