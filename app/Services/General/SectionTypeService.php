<?php

namespace App\Services\General;

use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

class SectionTypeService
{
    public function ensureHeroTypeExists(): SectionType
    {
        $heroSectionType = SectionType::firstOrCreate(['type' => 'hero']);

        if (!$heroSectionType->settings()->where('setting_key', 'front')->exists()) {
            SectionTypeSetting::updateOrCreate(
                ['section_type_id' => $heroSectionType->id, 'setting_key' => 'front'],
                ['value' => ['autoplay' => true, 'loop' => true, 'muted' => true]]
            );
        }

        if (!$heroSectionType->settings()->where('setting_key', 'back')->exists()) {
            SectionTypeSetting::updateOrCreate(
                ['section_type_id' => $heroSectionType->id, 'setting_key' => 'back'],
                ['value' => []]
            );
        }

        return $heroSectionType->load('settings');
    }

    public function getAll(): \Illuminate\Support\Collection
    {
        $this->ensureHeroTypeExists();
        return SectionType::get()->pluck('type');
    }

    public function getById(int $id): ?SectionType
    {
        return SectionType::with('settings')->find($id);
    }

    public function getByType(string $type): ?SectionType
    {
        if ($type === 'hero') {
            return $this->ensureHeroTypeExists();
        }

        return SectionType::with('settings')->where('type', $type)->first();
    }

    public function getSettingsGrouped(string $type): array
    {
        $sectionType = $this->getByType($type);

        if (!$sectionType) {
            return ['front' => [], 'back' => []];
        }

        $result = ['front' => [], 'back' => []];

        foreach ($sectionType->settings as $setting) {
            $result[$setting->setting_key] = $setting->value;
        }

        return $result;
    }

    public function createType(array $data): SectionType
    {
        return SectionType::create($data);
    }

    public function updateType(int $id, array $data): SectionType
    {
        $type = SectionType::findOrFail($id);
        $type->update($data);
        return $type;
    }

    public function deleteType(int $id): void
    {
        $type = SectionType::findOrFail($id);
        $type->delete();
    }

    public function upsertSettings(int $sectionTypeId, array $setting): SectionType
    {
        SectionTypeSetting::where('section_type_id', $sectionTypeId)->delete();

        if (array_key_exists('front', $setting) && is_array($setting['front'])) {
            SectionTypeSetting::create([
                'section_type_id' => $sectionTypeId,
                'setting_key' => 'front',
                'value' => $setting['front'],
            ]);
        }

        if (array_key_exists('back', $setting) && is_array($setting['back'])) {
            SectionTypeSetting::create([
                'section_type_id' => $sectionTypeId,
                'setting_key' => 'back',
                'value' => $setting['back'],
            ]);
        }

        return SectionType::with('settings')->findOrFail($sectionTypeId);
    }
}
