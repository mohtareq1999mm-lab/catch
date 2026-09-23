<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;

/**
 * Qatar master geography (country + 8 municipalities as governorate rows).
 *
 * Scope: master data ONLY. No cities, no shipping prices, no address/order/
 * coupon changes. Safe to run multiple times (country reuse by phone_code
 * with name-match fallback; governorates guarded by UNIQUE(country_id,name)
 * plus explicit pre-checks). Never deletes, truncates, or touches unrelated rows.
 */
class QatarLocationSeeder extends Seeder
{
    /**
     * @return array<int, array{en: string, ar: string}>
     */
    public static function municipalities(): array
    {
        return [
            ['en' => 'Doha', 'ar' => 'الدوحة'],
            ['en' => 'Al Rayyan', 'ar' => 'الريان'],
            ['en' => 'Al Wakra', 'ar' => 'الوكرة'],
            ['en' => 'Al Shahaniya', 'ar' => 'الشحانية'],
            ['en' => 'Al Daayen', 'ar' => 'الظعاين'],
            ['en' => 'Umm Salal', 'ar' => 'أم صلال'],
            ['en' => 'Al Khor and Al Thakhira', 'ar' => 'الخور والذخيرة'],
            ['en' => 'Al Shamal', 'ar' => 'الشمال'],
        ];
    }

    public function run(): void
    {
        $qatar = $this->resolveQatar();

        foreach (self::municipalities() as $names) {
            $this->seedMunicipality((int) $qatar->id, $names['en'], $names['ar']);
        }
    }

    /**
     * Canonical identity (project convention = phone_code, cf. LocationSeeder).
     * Reuses a pre-existing Qatar row matched by phone_code first, then by
     * stored en/ar name — never inserts a second Qatar.
     */
    public function resolveQatar(): Country
    {
        $existing = Country::query()->where('phone_code', '974')->first();

        if ($existing) {
            return $existing;
        }

        $byName = Country::query()->get()->first(
            fn (Country $c) => in_array($c->getTranslation('name', 'en'), ['Qatar'], true)
                || in_array($c->getTranslation('name', 'ar'), ['قطر'], true)
        );

        if ($byName) {
            return $byName;
        }

        return Country::create([
            'name' => ['en' => 'Qatar', 'ar' => 'قطر'],
            'phone_code' => '974',
            'status' => true,
        ]);
    }

    /**
     * Verify-or-create one municipality. Matches by country + en/ar name
     * (exact or either-side) so reruns and pre-existing rows converge.
     * Only normalizes status/name on rows already identified as the intended
     * municipality; touches nothing else.
     */
    private function seedMunicipality(int $countryId, string $en, string $ar): Governorate
    {
        $existing = Governorate::query()->where('country_id', $countryId)->get()->first(
            fn (Governorate $g) => $g->getTranslation('name', 'en') === $en
                || $g->getTranslation('name', 'ar') === $ar
        );

        if ($existing) {
            $updates = [];
            if ($existing->getTranslation('name', 'en') !== $en
                || $existing->getTranslation('name', 'ar') !== $ar) {
                $updates['name'] = ['en' => $en, 'ar' => $ar];
            }
            if (!$existing->status) {
                $updates['status'] = true;
            }
            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing->fresh();
        }

        try {
            return Governorate::create([
                'country_id' => $countryId,
                'name' => ['en' => $en, 'ar' => $ar],
                'status' => true,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // UNIQUE(country_id,name) won a concurrent insert race (e.g.
            // JSON key-order variant of the same municipality) — reload it.
            $winner = Governorate::query()->where('country_id', $countryId)->get()->first(
                fn (Governorate $g) => $g->getTranslation('name', 'en') === $en
                    || $g->getTranslation('name', 'ar') === $ar
            );

            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }
}
