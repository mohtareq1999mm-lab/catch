<?php

namespace App\Services\Warehouse;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Settings;

/**
 * Single runtime authority for the Warehouse Workflow ON/OFF switch.
 *
 * Business rule (Warehouse Workflow OFF = Orders-only mode): when OFF the
 * system operates on Orders only — every attempt to create or release a NEW
 * fulfillment is refused, while existing fulfillments keep operating through
 * their original lifecycle (transitions, picking, packing, shipping,
 * delivery). The referral to "existing fulfillments" is structural:
 * continuation code paths never consult this switch.
 *
 * Authority layers (in order; both must allow for ON — the master config can
 * only restrain, exactly like the runtime hierarchy for other kill switches):
 *
 *   Tier 1 (deployment master): config('warehouse.workflow_enabled')
 *                               ← env WAREHOUSE_WORKFLOW_ENABLED (default true)
 *   Tier 2 (admin runtime):     Settings.options.warehouse.workflow_enabled
 *                               (default true when the key or row is absent)
 *
 * The ONLY cached key here is `warehouse_workflow_switch` (own key, explicit
 * forget on write) — deliberately never the row-wide `cached_settings_*`
 * entries, whose 24h staleness is a proven defect (SettingsRegressionTest).
 *
 * Fail-closed policy:
 * - `isEnabled()` returns false when disabled by any tier OR when the stored
 *   state cannot be read — callers use it for SKIP decisions (automatic
 *   triggers and the recovery sweeper never create fulfillments on an
 *   unknown state).
 * - `assertNewFulfillmentAllowed()` throws for both refusals, with distinct
 *   translated messages: deliberately disabled vs. state unverifiable
 *   (infra failure). Callers must not create new fulfillments in either case.
 */
class WarehouseWorkflowSwitchService
{
    /**
     * Own cache key — NOT `cached_settings_{locale}` (never invalidated on
     * update; see tests/Feature/Settings/SettingsRegressionTest).
     */
    public const CACHE_KEY = 'warehouse_workflow_switch';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Whether the workflow is currently enabled. False when disabled by the
     * config master, disabled by the admin toggle, or unreadable
     * (infra failure is reported, not swallowed silently — fail-closed).
     */
    public function isEnabled(): bool
    {
        if (!$this->configEnabled()) {
            return false;
        }

        return $this->readStoredToggle() === true;
    }

    /**
     * Creation-boundary guard. Throws when a NEW fulfillment must not be
     * created: master config disabled, admin toggle disabled, or the stored
     * state cannot be determined (fail-closed for creation only).
     *
     * Callers map the RuntimeException to their existing 4xx convention.
     */
    public function assertNewFulfillmentAllowed(): void
    {
        if (!$this->configEnabled()) {
            throw new \RuntimeException(__('wms.workflow.switch_disabled'));
        }

        $stored = $this->readStoredToggle();

        if ($stored === null) {
            throw new \RuntimeException(__('wms.workflow.switch_unreadable'));
        }

        if (!$stored) {
            throw new \RuntimeException(__('wms.workflow.switch_disabled'));
        }
    }

    /**
     * Admin surface: both tiers separately plus the effective decision, so
     * the UI can explain WHY the workflow is off. `readable=false` means the
     * stored tier could not be read (effective=false by fail-closure).
     */
    public function describe(): array
    {
        try {
            $stored = (bool) data_get(
                $this->rememberedStoredState(),
                'workflow_enabled',
                true
            );
        } catch (\Throwable $e) {
            report($e);

            return [
                'config_enabled' => $this->configEnabled(),
                'stored_enabled' => null,
                'readable' => false,
                'effective_enabled' => false,
                'config_key' => 'warehouse.workflow_enabled',
                'env_key' => 'WAREHOUSE_WORKFLOW_ENABLED',
                'option_path' => 'settings.options.warehouse.workflow_enabled',
            ];
        }

        $configEnabled = $this->configEnabled();

        return [
            'config_enabled' => $configEnabled,
            'stored_enabled' => (bool) $stored,
            'readable' => true,
            'effective_enabled' => $configEnabled && $stored,
            'config_key' => 'warehouse.workflow_enabled',
            'env_key' => 'WAREHOUSE_WORKFLOW_ENABLED',
            'option_path' => 'settings.options.warehouse.workflow_enabled',
        ];
    }

    /**
     * Persist the admin runtime toggle inside Settings.options.warehouse
     * (merge keeps other warehouse keys and other option groups intact) and
     * invalidate this switch's cache. Manual mirrors the FastShipping
     * settings-write precedent (row lock + transaction + own-key forget).
     *
     * The settings row is expected to exist (single row bootstrap); a
     * missing row fails loudly instead of silently creating partial data.
     *
     * Returns the previous and new stored toggle values for the audit trail.
     */
    public function update(array $validated): array
    {
        $enabled = (bool) $validated['workflow_enabled'];

        $old = DB::transaction(function () use ($enabled): ?bool {
            $settings = Settings::query()->lockForUpdate()->firstOrFail();
            $options = $settings->options ?? [];
            $warehouseGroup = (array) data_get($options, 'warehouse', []);

            $old = array_key_exists('workflow_enabled', $warehouseGroup)
                ? (bool) $warehouseGroup['workflow_enabled']
                : null;

            $warehouseGroup['workflow_enabled'] = $enabled;
            $options['warehouse'] = $warehouseGroup;

            $settings->update(['options' => $options]);

            return $old;
        });

        Cache::forget(self::CACHE_KEY);

        return ['old_workflow_enabled' => $old, 'workflow_enabled' => $enabled];
    }

    /**
     * Tier 1 — deployment master. config() never throws (static registry);
     * default true keeps existing behavior when the file is absent
     * (e.g. a stale config:cache built before this file shipped).
     */
    private function configEnabled(): bool
    {
        return (bool) config('warehouse.workflow_enabled', true);
    }

    /**
     * Tier 2 — stored admin toggle. Null means "cannot determine"
     * (infrastructure failure, already reported).
     */
    private function readStoredToggle(): ?bool
    {
        try {
            return (bool) data_get($this->rememberedStoredState(), 'workflow_enabled', true);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Raw persistence seam. Protected (not private) so tests can force an
     * infrastructure read failure through a partial mock — fail-closed
     * behavior is part of the contract and must be directly testable.
     *
     * @return array stored `options.warehouse` group ([] when row/keys absent)
     */
    protected function fetchStoredState(): array
    {
        $options = Settings::query()->first()?->options ?? [];

        return (array) data_get($options, 'warehouse', []);
    }

    private function rememberedStoredState(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): array {
                return $this->fetchStoredState();
            }
        );
    }
}
