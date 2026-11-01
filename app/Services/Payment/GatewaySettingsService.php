<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\FrontendResource;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Currency\CurrencyService;
use App\Traits\HasCache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Settings;

/**
 * Registry settings source for payment gateways.
 *
 * Merges config('payment.gateways') with the optional
 * settings.options['payment_gateways'] overrides. A missing settings row
 * (or a database without the table, e.g. isolated unit tests) falls back
 * to config only.
 */
class GatewaySettingsService
{
    use HasCache;
    /**
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->merged();
    }

    public function definition(string $code): ?array
    {
        $gateways = $this->merged();

        $definition = $gateways[$code] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function isEnabled(string $code): bool
    {
        $definition = $this->definition($code);

        if ($definition === null) {
            return false;
        }

        return (bool) ($definition['enabled'] ?? true);
    }

    /**
     * @return array<int, string>
     */
    public function knownCodes(): array
    {
        return array_keys($this->merged());
    }

    /**
     * Admin view of every known gateway, sorted by sort_order.
     *
     * Secrets are NEVER included: supported_currencies/methods and the
     * configured flag are derived from env-backed config only. Only
     * {enabled, display_name, sort_order} may come from settings.options.
     *
     * @return array<int, array{code: string, display_name: string, enabled: bool, configured: bool, supported_currencies: array<int, string>, methods: array<int, string>, sort_order: int}>
     */
    public function getAdminView(): array
    {
        $configured = config('payment.gateways', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $overrides = $this->settingsOverrides();
        $merged = $this->merged();

        $rows = [];
        $index = 0;
        foreach ($merged as $code => $definition) {
            if (!is_array($definition)) {
                $definition = [];
            }
            $base = $configured[$code] ?? [];
            if (!is_array($base)) {
                $base = [];
            }
            $override = $overrides[$code] ?? [];
            if (!is_array($override)) {
                $override = [];
            }

            $rows[] = [
                'code' => $code,
                'display_name' => (string) ($override['display_name'] ?? $base['display_name'] ?? $code),
                'enabled' => (bool) ($definition['enabled'] ?? true),
                'configured' => $this->isAdapterConfigured($base),
                'supported_currencies' => array_values((array) ($base['supported_currencies'] ?? [])),
                'methods' => array_values((array) ($base['methods'] ?? [])),
                'sort_order' => (int) ($override['sort_order'] ?? $base['sort_order'] ?? $index),
            ];
            $index++;
        }

        usort($rows, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['code'], $b['code']));

        return $rows;
    }

    public function adminRow(string $code): ?array
    {
        foreach ($this->getAdminView() as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Client-safe public view of every known gateway, in admin sort order.
     *
     * Same source as getAdminView() (same merge, same order), trimmed to
     * what the storefront needs: {code, display_name, enabled, available,
     * supported_currencies, supports_catalog_currency}.
     *
     * enabled is the admin toggle from settings.options['payment_gateways'],
     * so a gateway disabled from Site Settings is reported as unavailable
     * without any frontend hard-coding. available is a DISPLAY-layer verdict:
     * enabled && configured && supports_catalog_currency. Internal fields
     * (configured, methods, sort_order) stay admin-only and secrets are
     * never present.
     *
     * This snapshot is NOT a security boundary: the order moment may see a
     * different setting, and per-provider eligibility (plan/amount checks,
     * shipping method, Tamara isEligible etc.) is order-specific. Checkout
     * stays the final authority (HTTP 422) when a setting changes between
     * this read and order submit.
     *
     * @return array<int, array{code: string, display_name: string, enabled: bool, available: bool, supported_currencies: array<int, string>, supports_catalog_currency: bool}>
     */
    public function getPublicView(string $catalogCode): array
    {
        $catalogCode = strtoupper($catalogCode);

        return array_map(function (array $row) use ($catalogCode) {
            $supported = array_map('strtoupper', array_values((array) ($row['supported_currencies'] ?? [])));
            $supportsCatalogCurrency = in_array($catalogCode, $supported, true);

            return [
                'code' => $row['code'],
                'display_name' => $row['display_name'],
                'enabled' => (bool) $row['enabled'],
                'available' => (bool) $row['enabled'] && (bool) ($row['configured'] ?? false) && $supportsCatalogCurrency,
                'supported_currencies' => $supported,
                'supports_catalog_currency' => $supportsCatalogCurrency,
            ];
        }, $this->getAdminView());
    }

    /**
     * Compact {code => {enabled, available}} map for the Settings responses
     * (SettingResource renders it on both GET settings endpoints).
     *
     * available is the same display-layer verdict as getPublicView():
     * enabled && configured && supports_catalog_currency. NOT a security
     * boundary — checkout stays the final authority (HTTP 422). Secrets and
     * the other internal fields (configured, methods, sort_order) never
     * leave the service here.
     *
     * Caching: computed under the SAME FrontendResource::SETTINGS tag every
     * write path flushes (settings saves, admin gateway toggles, currency
     * catalog writes), so a toggle invalidates the map instantly. The 15min
     * TTL bounds staleness for out-of-band DB edits only. Invalidation is
     * correctness-complete because every legitimate mutating path flushes
     * the tag; the TTL is purely self-healing.
     *
     * @return array<string, array{enabled: bool, available: bool}>
     */
    public function clientAvailabilityMap(): array
    {
        return $this->remember(
            FrontendResource::SETTINGS->value,
            'payment_gateways.client_availability_map',
            fn () => $this->buildClientAvailabilityMap(),
            now()->addMinutes(15),
        );
    }

    /**
     * Compute the availability map (cache-miss path). Never throws.
     *
     * @return array<string, array{enabled: bool, available: bool}>
     */
    private function buildClientAvailabilityMap(): array
    {
        try {
            $catalogCode = strtoupper((string) app(CurrencyService::class)->getCatalogCode());
        } catch (\Throwable $e) {
            // Currency subsystem failure must never break the settings
            // response; degrade to the payment default currency. The
            // availability verdict then reflects that currency's support.
            $catalogCode = strtoupper((string) config('payment.default_currency', 'KWD'));
        }

        $map = [];
        foreach ($this->getPublicView($catalogCode) as $row) {
            $map[$row['code']] = [
                'enabled' => (bool) $row['enabled'],
                'available' => (bool) $row['available'],
            ];
        }

        return $map;
    }

    /**
     * Persist an admin override. ONLY the {enabled, display_name, sort_order}
     * allowlist is stored — secrets, class, supported_currencies and methods
     * present in $data are ignored and stay env-only. An explicit null for
     * display_name/sort_order clears that override back to the config default.
     *
     * @throws \InvalidArgumentException when $code is not a known gateway.
     */
    public function updateOverride(string $code, array $data): array
    {
        if ($this->adminRow($code) === null) {
            throw new \InvalidArgumentException("Unknown payment gateway: {$code}");
        }

        $patch = [];
        if (array_key_exists('enabled', $data)) {
            $patch['enabled'] = (bool) $data['enabled'];
        }
        if (array_key_exists('display_name', $data) && $data['display_name'] !== null) {
            $patch['display_name'] = (string) $data['display_name'];
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $patch['sort_order'] = (int) $data['sort_order'];
        }

        DB::transaction(function () use ($code, $patch, $data) {
            $settings = Settings::query()->lockForUpdate()->first();

            if (!$settings) {
                throw new \RuntimeException('Settings record not found.');
            }

            $options = $settings->options ?? [];
            if (!is_array($options)) {
                $options = [];
            }
            $gateways = $options['payment_gateways'] ?? [];
            if (!is_array($gateways)) {
                $gateways = [];
            }

            $existing = $gateways[$code] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }

            foreach ($patch as $key => $value) {
                $existing[$key] = $value;
            }

            if (array_key_exists('display_name', $data) && $data['display_name'] === null) {
                unset($existing['display_name']);
            }
            if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
                unset($existing['sort_order']);
            }

            if ($existing === []) {
                unset($gateways[$code]);
            } else {
                $gateways[$code] = $existing;
            }

            $options['payment_gateways'] = $gateways;
            $settings->options = $options;
            $settings->save();
        });

        $row = $this->adminRow($code);

        if ($row === null) {
            throw new \RuntimeException("Payment gateway view missing after update: {$code}");
        }

        return $row;
    }

    /**
     * @return array<string, array>
     */
    private function merged(): array
    {
        $configured = config('payment.gateways', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        foreach ($this->settingsOverrides() as $code => $override) {
            if (!is_array($override)) {
                continue;
            }
            $base = $configured[$code] ?? [];
            if (!is_array($base)) {
                $base = [];
            }
            // READ allowlist: only {enabled, display_name, sort_order} may
            // come from settings.options. Secrets, class,
            // supported_currencies and methods stay env-only even if a stale
            // or hostile DB row carries them (write path already filters, but
            // defense in depth on read keeps a bad row from hijacking the
            // adapter class or widening currencies).
            $override = array_intersect_key($override, ['enabled' => true, 'display_name' => true, 'sort_order' => true]);
            $configured[$code] = array_merge($base, $override);
        }

        return $configured;
    }

    /**
     * @return array<string, array>
     */
    private function settingsOverrides(): array
    {
        try {
            $settings = Settings::query()->first();

            if (!$settings) {
                return [];
            }

            $options = $settings->options ?? [];
            if (!is_array($options)) {
                return [];
            }

            $gateways = $options['payment_gateways'] ?? [];

            return is_array($gateways) ? $gateways : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Safely resolve the env-backed adapter class and report its
     * isConfigured() state. A missing class or any failure is fail-closed
     * (false) — the admin view must never throw because of an adapter.
     */
    private function isAdapterConfigured(array $base): bool
    {
        try {
            $class = $base['class'] ?? null;

            if (!is_string($class) || $class === '' || !class_exists($class)) {
                return false;
            }

            $instance = app($class);

            if (!$instance instanceof PaymentGatewayContract) {
                return false;
            }

            if (method_exists($instance, 'isConfigured')) {
                return (bool) $instance->isConfigured();
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
