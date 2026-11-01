<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Settings;

class PaymentGatewaySeeder extends Seeder
{
    use HasCache;

    /**
     * Canonical baseline definitions for all registered payment gateways.
     *
     * Only {enabled, display_name, sort_order} are persisted in
     * settings.options['payment_gateways'][code], matching the strict allowlist
     * in App\Services\Payment\GatewaySettingsService. Secrets, adapter class,
     * supported_currencies, and methods remain strictly in config/payment.php.
     *
     * @var array<string, array{display_name: string, enabled: bool, sort_order: int}>
     */
    public const GATEWAYS = [
        'myfatoorah' => [
            'display_name' => 'MyFatoorah',
            'enabled' => true,
            'sort_order' => 0,
        ],
        'stripe' => [
            'display_name' => 'Stripe',
            'enabled' => false,
            'sort_order' => 1,
        ],
        'paypal' => [
            'display_name' => 'PayPal',
            'enabled' => false,
            'sort_order' => 2,
        ],
        'tap' => [
            'display_name' => 'Tap',
            'enabled' => false,
            'sort_order' => 3,
        ],
        'tabby' => [
            'display_name' => 'Tabby',
            'enabled' => false,
            'sort_order' => 4,
        ],
        'tamara' => [
            'display_name' => 'Tamara',
            'enabled' => false,
            'sort_order' => 5,
        ],
    ];

    /**
     * Seed all payment gateways into settings.options['payment_gateways'].
     *
     * Idempotent: preserves existing admin overrides (enabled, display_name,
     * sort_order) if already present in the database while backfilling any
     * missing gateways or keys.
     */
    public function run(): void
    {
        $setting = Settings::query()->first();

        if (! $setting) {
            $this->call(SettingSeeder::class);
            $setting = Settings::query()->first();
        }

        if (! $setting) {
            return;
        }

        $options = is_array($setting->options) ? $setting->options : [];
        $storedGateways = is_array($options['payment_gateways'] ?? null)
            ? $options['payment_gateways']
            : [];

        $configuredGateways = config('payment.gateways', []);
        if (! is_array($configuredGateways)) {
            $configuredGateways = [];
        }

        $definitions = self::GATEWAYS;
        $nextSortOrder = count($definitions);

        foreach ($configuredGateways as $code => $configDef) {
            if (! is_string($code) || $code === '' || isset($definitions[$code])) {
                continue;
            }
            $configDef = is_array($configDef) ? $configDef : [];
            $definitions[$code] = [
                'display_name' => (string) ($configDef['display_name'] ?? ucfirst($code)),
                'enabled' => (bool) ($configDef['enabled'] ?? false),
                'sort_order' => (int) ($configDef['sort_order'] ?? $nextSortOrder++),
            ];
        }

        foreach ($definitions as $code => $defaults) {
            $configDef = is_array($configuredGateways[$code] ?? null)
                ? $configuredGateways[$code]
                : [];

            $existing = is_array($storedGateways[$code] ?? null)
                ? $storedGateways[$code]
                : [];

            $defaultEnabled = array_key_exists('enabled', $configDef)
                ? (bool) $configDef['enabled']
                : $defaults['enabled'];

            $defaultDisplayName = isset($configDef['display_name']) && is_string($configDef['display_name']) && $configDef['display_name'] !== ''
                ? $configDef['display_name']
                : $defaults['display_name'];

            $defaultSortOrder = isset($configDef['sort_order']) && is_numeric($configDef['sort_order'])
                ? (int) $configDef['sort_order']
                : $defaults['sort_order'];

            $storedGateways[$code] = [
                'enabled' => array_key_exists('enabled', $existing)
                    ? (bool) $existing['enabled']
                    : $defaultEnabled,
                'display_name' => isset($existing['display_name']) && is_string($existing['display_name']) && $existing['display_name'] !== ''
                    ? $existing['display_name']
                    : $defaultDisplayName,
                'sort_order' => isset($existing['sort_order']) && is_numeric($existing['sort_order'])
                    ? (int) $existing['sort_order']
                    : $defaultSortOrder,
            ];
        }

        $options['payment_gateways'] = $storedGateways;
        $setting->options = $options;
        $setting->save();

        $this->flushTag(FrontendResource::SETTINGS->value);
    }
}

