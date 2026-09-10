<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\CurrencyRate;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    private const CURRENCIES = [
        [
            'code' => 'USD',
            'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
            'symbol' => ['en' => '$', 'ar' => '$'],
            'country_name' => ['en' => 'United States', 'ar' => 'الولايات المتحدة'],
            'numeric_code' => '840',
            'decimal_places' => 2,
            'icon' => 'us',
            'is_active' => true,
            'sort_order' => 1,
            'rate' => '1.0000000000',
        ],
        [
            'code' => 'KWD',
            'name' => ['en' => 'Kuwaiti Dinar', 'ar' => 'دينار كويتي'],
            'symbol' => ['en' => 'د.ك', 'ar' => 'د.ك'],
            'country_name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
            'numeric_code' => '414',
            'decimal_places' => 3,
            'icon' => 'kw',
            'is_active' => true,
            'sort_order' => 2,
            'rate' => '0.2210000000',
        ],
        [
            'code' => 'SAR',
            'name' => ['en' => 'Saudi Riyal', 'ar' => 'ريال سعودي'],
            'symbol' => ['en' => 'ر.س', 'ar' => 'ر.س'],
            'country_name' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'],
            'numeric_code' => '682',
            'decimal_places' => 2,
            'icon' => 'sa',
            'is_active' => true,
            'sort_order' => 3,
            'rate' => '3.7500000000',
        ],
        [
            'code' => 'AED',
            'name' => ['en' => 'UAE Dirham', 'ar' => 'درهم إماراتي'],
            'symbol' => ['en' => 'د.إ', 'ar' => 'د.إ'],
            'country_name' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة'],
            'numeric_code' => '784',
            'decimal_places' => 2,
            'icon' => 'ae',
            'is_active' => true,
            'sort_order' => 4,
            'rate' => '3.6725000000',
        ],
        [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'symbol' => ['en' => '€', 'ar' => '€'],
            'country_name' => ['en' => 'European Union', 'ar' => 'الاتحاد الأوروبي'],
            'numeric_code' => '978',
            'decimal_places' => 2,
            'icon' => 'eu',
            'is_active' => true,
            'sort_order' => 5,
            'rate' => '0.9990000000',
        ],
        [
            'code' => 'GBP',
            'name' => ['en' => 'British Pound', 'ar' => 'جنيه إسترليني'],
            'symbol' => ['en' => '£', 'ar' => '£'],
            'country_name' => ['en' => 'United Kingdom', 'ar' => 'المملكة المتحدة'],
            'numeric_code' => '826',
            'decimal_places' => 2,
            'icon' => 'gb',
            'is_active' => true,
            'sort_order' => 6,
            'rate' => '0.8600000000',
        ],
    ];

    public function run(): void
    {
        $today = now()->toDateString();

        foreach (self::CURRENCIES as $item) {
            $rate = $item['rate'];
            unset($item['rate']);

            $currency = Currency::query()->firstOrCreate(
                ['code' => $item['code']],
                $item,
            );

            CurrencyRate::query()->firstOrCreate(
                [
                    'currency_id' => $currency->id,
                    'effective_date' => $today,
                ],
                [
                    'exchange_rate' => $rate,
                ],
            );

            if ($currency->manual_rate === null) {
                $currency->update([
                    'rate_mode' => 'manual',
                    'manual_rate' => $rate,
                ]);
            }
        }
    }
}
