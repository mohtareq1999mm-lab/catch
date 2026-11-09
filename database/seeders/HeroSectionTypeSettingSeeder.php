<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

class HeroSectionTypeSettingSeeder extends Seeder
{
    public function run(): void
    {
        $item = [
            'type' => 'hero',
            'front' => ['autoplay' => true, 'loop' => true, 'muted' => true],
            'back' => ["slug" => "",],
        ];

        $sectionType = SectionType::firstOrCreate(['type' => $item['type']]);

        SectionTypeSetting::updateOrCreate(
            ['section_type_id' => $sectionType->id, 'setting_key' => 'front'],
            ['value' => $item['front']]
        );

        SectionTypeSetting::updateOrCreate(
            ['section_type_id' => $sectionType->id, 'setting_key' => 'back'],
            ['value' => $item['back']]
        );
    }
}

