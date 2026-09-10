<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('section_settings');

        try {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropUnique('sections_type_unique');
            });
        } catch (\Exception $e) {
            // index may not exist on fresh migrate (migration 000008 was removed)
        }
    }

    public function down(): void
    {
        try {
            Schema::table('sections', function (Blueprint $table) {
                $table->unique('type', 'sections_type_unique');
            });
        } catch (\Exception $e) {
            //
        }

        Schema::create('section_settings', function (Blueprint $table) {
            $table->id();
            $table->string('section_type');
            $table->string('setting_key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->foreign('section_type')->references('type')->on('sections')->onDelete('cascade');
            $table->unique(['section_type', 'setting_key']);
        });
    }
};
