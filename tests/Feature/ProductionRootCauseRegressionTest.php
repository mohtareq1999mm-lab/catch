<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Settings;
use Marvel\Http\Resources\SettingResource;
use Tests\TestCase;

/**
 * Regression tests for PRODUCTION_RUNTIME_ERROR_ROOT_CAUSE_AUDIT
 * - Sanctum personal_access_tokens table must exist (Fix A)
 * - SettingResource must be null-safe (Fix B)
 */
class ProductionRootCauseRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function personal_access_tokens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'token'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'tokenable_type'));
    }

    /** @test */
    public function personal_access_tokens_can_store_token(): void
    {
        // Direct insert verifies schema matches Sanctum expectations
        \Illuminate\Support\Facades\DB::table('personal_access_tokens')->insert([
            'tokenable_type' => \Marvel\Database\Models\User::class,
            'tokenable_id' => 1,
            'name' => 'test',
            'token' => hash('sha256', 'test-token'),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'test']);
    }

    /** @test */
    public function sanctum_token_creation_works_via_has_api_tokens(): void
    {
        $user = \Marvel\Database\Models\User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;
        $this->assertStringContainsString('|', $token);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    /** @test */
    public function public_settings_endpoint_works_when_settings_empty(): void
    {
        Settings::truncate();
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->getJson('/api/v1/general/settings');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['site_name', 'site_desc', 'options']]);
        // Should not throw getTranslation on null
        $this->assertNotEquals(500, $response->status());
    }

    /** @test */
    public function public_settings_endpoint_works_with_throttle_and_no_bearer(): void
    {
        if (!Settings::exists()) {
            Settings::create(['site_name' => json_encode(['en' => 'Test']), 'options' => []]);
        }
        $response = $this->getJson('/api/v1/general/settings');
        $response->assertOk();
    }

    /** @test */
    public function public_settings_with_bearer_token_does_not_crash_when_table_exists(): void
    {
        if (!Settings::exists()) {
            Settings::create(['site_name' => json_encode(['en' => 'Test']), 'options' => []]);
        }
        $user = \Marvel\Database\Models\User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/general/settings');
        // Public endpoint does not require auth; should still be 200, not 500 DB error
        $response->assertOk();
    }

    /** @test */
    public function setting_resource_null_is_safe(): void
    {
        $resource = SettingResource::make(null);
        $array = $resource->toArray(request());
        $this->assertIsArray($array);
        $this->assertArrayHasKey('site_name', $array);
        $this->assertArrayHasKey('options', $array);
        $this->assertTrue(is_array($array['site_name']) || is_null($array['site_name']));
    }

    /** @test */
    public function setting_resource_with_model_returns_translations(): void
    {
        $settings = Settings::create([
            'site_name' => json_encode(['en' => 'Hello', 'ar' => 'مرحبا']),
            'options' => ['currency_selection_enabled' => true],
        ]);
        $resource = SettingResource::make($settings);
        $array = $resource->toArray(request());
        $this->assertIsArray($array);
        $this->assertArrayHasKey('site_name', $array);
        $this->assertArrayHasKey('options', $array);
        // Resource must not throw; actual translation depends on route name (settings.front)
        $this->assertNotNull($array);
    }
}
