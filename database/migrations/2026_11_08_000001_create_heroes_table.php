<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('heroes')) {
            Schema::create('heroes', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20)->default('image');
                $table->json('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        // Register 'hero' in section_types and section_type_settings if tables exist
        if (Schema::hasTable('section_types')) {
            $now = now();
            DB::table('section_types')->updateOrInsert(
                ['type' => 'hero'],
                ['updated_at' => $now, 'created_at' => $now]
            );

            if (Schema::hasTable('section_type_settings')) {
                $sectionTypeId = DB::table('section_types')->where('type', 'hero')->value('id');
                if ($sectionTypeId) {
                    DB::table('section_type_settings')->updateOrInsert(
                        ['section_type_id' => $sectionTypeId, 'setting_key' => 'front'],
                        [
                            'value' => json_encode(['autoplay' => true, 'loop' => true, 'muted' => true]),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );

                    DB::table('section_type_settings')->updateOrInsert(
                        ['section_type_id' => $sectionTypeId, 'setting_key' => 'back'],
                        [
                            'value' => json_encode([]),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('section_types')) {
            $sectionTypeId = DB::table('section_types')->where('type', 'hero')->value('id');
            if ($sectionTypeId && Schema::hasTable('section_type_settings')) {
                DB::table('section_type_settings')->where('section_type_id', $sectionTypeId)->delete();
            }
            DB::table('section_types')->where('type', 'hero')->delete();
        }

        Schema::dropIfExists('heroes');
    }
};

