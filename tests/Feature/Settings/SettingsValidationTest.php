<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->user = $this->createSuperAdmin();
        Sanctum::actingAs($this->user);
    }

    private function createSuperAdmin(): User
    {
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_SETTINGS, self::GUARD);
        Permission::findOrCreate(PermissionEnum::UPDATE_SETTINGS, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        $role->givePermissionTo([
            PermissionEnum::VIEW_SETTINGS,
            PermissionEnum::UPDATE_SETTINGS]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        return $user;
    }

    private function validPayload(): array
    {
        return [
            'site_name' => ['en' => 'My Store'],
            'site_desc' => ['en' => 'A great store description'],
            'meta_desc' => ['en' => 'SEO meta description'],
            'site_copy_right' => ['en' => '2024 My Store'],
            'site_email' => 'admin@store.com',
            'email_support' => 'support@store.com',
            'facebook' => 'https://facebook.com/store',
            'instagram' => 'https://instagram.com/store',
            'linkedin' => 'https://linkedin.com/store',
            'youtube' => 'https://youtube.com/store',
            'tiktok' => 'https://tiktok.com/store',
            'snapchat' => 'https://snapchat.com/store',
            'phone' => '1234567890',
            'fast_shipping_page_publish' => '1'];
    }

    private function createExistingSettings(): void
    {
        Settings::create([
            'site_name' => json_encode(['en' => 'Existing']),
            'options' => []]);
    }

    /** @test */
    public function update_returns_422_without_site_name(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['site_name'] = null;

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_without_site_email(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['site_email'] = null;

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_with_invalid_email(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['site_email'] = 'not-an-email';

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_with_invalid_url(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['facebook'] = 'not-a-url';

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_with_invalid_tiktok_url(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['tiktok'] = 'not-a-url';

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_with_invalid_snapchat_url(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['snapchat'] = 'not-a-url';

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_without_fast_shipping_page_publish(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['fast_shipping_page_publish'] = null;

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_422_with_invalid_fast_shipping_value(): void
    {
        $this->createExistingSettings();

        $payload = $this->validPayload();
        $payload['fast_shipping_page_publish'] = 'invalid';

        $response = $this->putJson(self::PREFIX . '/settings', $payload);

        $response->assertStatus(422);
    }
}
