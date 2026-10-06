<?php

namespace Database\Seeders;

use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Models\Currency;
use App\Models\CurrencyRate;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;

/**
 * Financial Currency end-to-end verification fixtures.
 *
 * Seeds ONLY reference/master data (settings, currencies, pinned FX rows,
 * product, promotion, customer). Orders, transactions, refunds and reports
 * are produced exclusively through the real application flows in
 * FinancialCurrencyEndToEndTest — never inserted here.
 *
 * Idempotent: every record is resolved by a deterministic natural key and
 * converged to the canonical verification values on repeat runs. Safe for
 * test/verification environments. DO NOT run against production: pinned
 * MANUAL rates would freeze FX for the seeded currencies.
 */
class FinancialCurrencyVerificationSeeder extends Seeder
{
    public const CUSTOMER_EMAIL = 'fin.verify@example.com';
    public const PRODUCT_SLUG = 'fin-currency-verify-001';
    public const PROMOTION_CODE = 'FIN-VERIFY-50';

    /** SEEDED TEST RATES (anchor-relative, like provider rows — not live FX). */
    public const RATES = [
        'USD' => '1.0000000000',
        'SAR' => '3.7500000000',
        'EUR' => '0.8500000000',
        'KWD' => '0.3070000000',
    ];

    public function run(): void
    {
        // Hard production guard: pinned MANUAL rates would freeze live FX
        // and fixture rows would pollute business data. Verification only.
        abort_if(app()->isProduction(), 403, 'FinancialCurrencyVerificationSeeder is verification-only.');

        $settings = Settings::query()->first();
        if (!$settings) {
            $settings = Settings::create([
                'site_name' => ['en' => 'Verification', 'ar' => 'تحقق'],
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }
        $options = $settings->options ?? [];
        $options['base_currency_code'] ??= 'USD';
        $options['currency'] ??= 'USD';
        $options['catalog_currency_code'] ??= 'USD';
        $settings->options = $options;
        $settings->save();

        $meta = [
            'USD' => ['840', 2, 'US Dollar', '$'],
            'SAR' => ['682', 2, 'Saudi Riyal', 'ر.س'],
            'EUR' => ['978', 2, 'Euro', '€'],
            'KWD' => ['414', 3, 'Kuwaiti Dinar', 'د.ك'],
        ];

        foreach (self::RATES as $code => $rate) {
            [$numeric, $dp, $name, $symbol] = $meta[$code];

            $currency = Currency::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => ['en' => $name, 'ar' => $name],
                    'symbol' => ['en' => $symbol, 'ar' => $symbol],
                    'country_name' => ['en' => $name, 'ar' => $name],
                    'numeric_code' => $numeric,
                    'decimal_places' => $dp,
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );
            // Pinned MANUAL mode: provider sync skips these rows, so the
            // verification rates stay deterministic across sync runs.
            $currency->update(['is_active' => true, 'rate_mode' => RateMode::MANUAL]);

            CurrencyRate::query()->updateOrCreate(
                [
                    'currency_id' => $currency->id,
                    'effective_date' => now()->toDateString(),
                ],
                [
                    // SEEDED TEST RATE — explicit fixture, not provider FX.
                    'exchange_rate' => $rate,
                    'source' => RateSource::MANUAL,
                    'provider' => 'verification-seed',
                ]
            );
        }

        Product::query()->firstOrCreate(
            ['slug' => self::PRODUCT_SLUG],
            [
                'name' => 'Fin Currency Verify Product',
                'price' => 100.0,
                'product_type' => 'simple',
                'status' => true,
                'in_stock' => true,
                'stock_quantity' => 1000,
                'reserved_quantity' => 0,
                'has_discount' => false,
                'has_flash_sale' => false,
            ]
        );

        Promotion::query()->firstOrCreate(
            ['code' => self::PROMOTION_CODE],
            [
                'name' => 'Fin Verify 50 Off',
                'slug' => 'fin-verify-50',
                'type' => PromotionType::PRICE,
                'type_amount' => PromotionMountType::FIXED_RATE,
                'value' => 50,
                'discount' => 50,
                'minimum_order_amount' => 0,
                'apply_to' => 'all_products',
                'status' => true,
                'start_at' => now()->subDay()->format('Y-m-d'),
                'end_at' => now()->addMonth()->format('Y-m-d'),
            ]
        );

        User::query()->firstOrCreate(
            ['email' => self::CUSTOMER_EMAIL],
            [
                'name' => 'Fin Verify Customer',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'is_active' => true,
                'type' => 'user',
            ]
        );
    }
}
