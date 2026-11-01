<?php

namespace Tests\Unit\Services\Warehouse;

use App\Services\Warehouse\WarehouseWorkflowSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Settings;
use Tests\TestCase;

/**
 * WAREHOUSE-WORKFLOW-SWITCH — unit contract for the runtime ON/OFF authority.
 *
 * Truth table, merge semantics, cache invalidation, and fail-closed behavior.
 * MySQL (RefreshDatabase) because the stored tier reads the settings row.
 */
class WarehouseWorkflowSwitchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // array cache driver persists per process — isolate the switch key.
        Cache::forget(WarehouseWorkflowSwitchService::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        config(['warehouse.workflow_enabled' => true]);
        Cache::forget(WarehouseWorkflowSwitchService::CACHE_KEY);
        parent::tearDown();
    }

    private function service(): WarehouseWorkflowSwitchService
    {
        return app(WarehouseWorkflowSwitchService::class);
    }

    private function settingsRow(array $options = []): Settings
    {
        return Settings::create(array_merge([
            'site_name' => json_encode(['en' => 'Switch Test'], JSON_THROW_ON_ERROR),
            'options' => $options,
        ]));
    }

    // ---------- truth table ----------

    public function test_defaults_to_enabled_without_row_or_key(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isEnabled());

        $service->assertNewFulfillmentAllowed(); // must not throw

        $state = $service->describe();
        $this->assertTrue($state['config_enabled']);
        $this->assertTrue($state['stored_enabled']);
        $this->assertTrue($state['readable']);
        $this->assertTrue($state['effective_enabled']);
    }

    public function test_missing_row_after_absent_settings_defaults_enabled(): void
    {
        // Row exists but the warehouse group is absent entirely.
        $this->settingsRow(['currency' => 'USD']);

        $this->assertTrue($this->service()->isEnabled());
    }

    public function test_stored_false_disables(): void
    {
        $this->settingsRow(['warehouse' => ['workflow_enabled' => false]]);

        $this->assertFalse($this->service()->isEnabled());

        try {
            $this->service()->assertNewFulfillmentAllowed();
            $this->fail('disabled workflow must refuse new fulfillment creation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Warehouse workflow is disabled', $e->getMessage());
        }
    }

    public function test_master_config_false_wins_over_stored_true(): void
    {
        $this->settingsRow(['warehouse' => ['workflow_enabled' => true]]);
        config(['warehouse.workflow_enabled' => false]);

        $this->assertFalse($this->service()->isEnabled());

        try {
            $this->service()->assertNewFulfillmentAllowed();
            $this->fail('master config false must refuse regardless of the admin toggle');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Warehouse workflow is disabled', $e->getMessage());
        }
    }

    public function test_master_config_true_defers_to_stored_false(): void
    {
        $this->settingsRow(['warehouse' => ['workflow_enabled' => false]]);
        config(['warehouse.workflow_enabled' => true]);

        $this->assertFalse($this->service()->isEnabled());
    }

    public function test_stored_missing_after_row_exists_defaults_enabled(): void
    {
        // Row exists but the warehouse group has no toggle key —
        // absent key defaults true.
        $this->settingsRow(['warehouse' => ['other' => 1]]);

        $this->assertTrue($this->service()->isEnabled());
    }

    // ---------- update (write path) ----------

    public function test_update_merges_without_losing_sibling_keys(): void
    {
        $row = $this->settingsRow([
            'currency' => 'USD',
            'warehouse' => ['future_flag' => 1],
        ]);

        $result = $this->service()->update(['workflow_enabled' => false]);

        $row->refresh();
        $raw = $row->options;

        // The toggle itself.
        $this->assertEquals(false, $raw['warehouse']['workflow_enabled']);
        // Sibling keys inside the warehouse group preserved.
        $this->assertEquals(1, $raw['warehouse']['future_flag']);
        // Sibling groups outside the warehouse group preserved.
        $this->assertEquals('USD', $raw['currency']);

        $this->assertFalse($this->service()->isEnabled());
    }

    public function test_update_returns_previous_value_for_audit(): void
    {
        // Single-row bootstrap contract (SettingSeeder): writers presume the
        // row exists — same posture as FastShippingRepository::updateSettings
        // and Marvel SettingsController@update, which never create partial rows.
        $this->settingsRow();

        $this->service()->update(['workflow_enabled' => false]);

        $second = $this->service()->update(['workflow_enabled' => true]);

        $this->assertFalse($second['old_workflow_enabled']);
        $this->assertTrue($second['workflow_enabled']);
        $this->assertTrue($this->service()->isEnabled());
    }

    public function test_update_without_bootstrap_row_fails_loudly(): void
    {
        // Never silently create a partial settings row (would leave site_name
        // etc. null for every other consumer); a missing row must fail loudly.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service()->update(['workflow_enabled' => false]);
    }

    public function test_write_invalidates_the_switch_cache(): void
    {
        $this->settingsRow(['warehouse' => ['workflow_enabled' => true]]);
        $this->assertTrue($this->service()->isEnabled());
        $this->assertNotNull(Cache::get(WarehouseWorkflowSwitchService::CACHE_KEY));

        $this->service()->update(['workflow_enabled' => false]);

        // update() forgets its own key — the next read reflects the DB,
        // never a stale entry (the class of staleness proven for
        // cached_settings_{locale} in SettingsRegressionTest).
        $this->assertNull(Cache::get(WarehouseWorkflowSwitchService::CACHE_KEY));
        $this->assertFalse($this->service()->isEnabled());
    }

    // ---------- fail-closed ----------

    public function test_unreadable_state_fails_closed_for_creation_and_skip(): void
    {
        config(['warehouse.workflow_enabled' => true]);

        $failing = new class extends WarehouseWorkflowSwitchService {
            protected function fetchStoredState(): array
            {
                throw new \PDOException('db down');
            }
        };

        // Skip decisions see "not enabled" — automatic triggers refuse.
        $this->assertFalse($failing->isEnabled());

        // Creation refusals tell ops WHY (distinct from the deliberate-off message).
        try {
            $failing->assertNewFulfillmentAllowed();
            $this->fail('unverifiable state must refuse new fulfillment creation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be determined', $e->getMessage());
        }

        $state = $failing->describe();
        $this->assertFalse($state['readable']);
        $this->assertNull($state['stored_enabled']);
        $this->assertFalse($state['effective_enabled']);
    }
}
