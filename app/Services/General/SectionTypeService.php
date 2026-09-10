<?php

namespace App\Services\General;

use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

class SectionTypeService
{
    public function getAll(): \Illuminate\Support\Collection
    {
        return SectionType::get()->pluck('type');
    }

    public function getById(int $id): ?SectionType
    {
        return SectionType::with('settings')->find($id);
    }

    public function getByType(string $type): ?SectionType
    {
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

        if ($front = $setting['front'] ?? null) {
            SectionTypeSetting::create([
                'section_type_id' => $sectionTypeId,
                'setting_key' => 'front',
                'value' => $front,
            ]);
        }

        if ($back = $setting['back'] ?? null) {
            SectionTypeSetting::create([
                'section_type_id' => $sectionTypeId,
                'setting_key' => 'back',
                'value' => $back,
            ]);
        }

        return SectionType::with('settings')->findOrFail($sectionTypeId);
    }
}
