<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** @test */
    public function guests_can_view_settings(): void
    {
        Settings::create([
            'site_name' => json_encode(['en' => 'Test Site']),
            'options' => ['currency' => 'USD'],
        ]);

        $response = $this->getJson(self::PREFIX . '/general/settings');

        $response->assertOk();
    }

    /** @test */
    public function guests_cannot_update_settings(): void
    {
        $response = $this->putJson(self::PREFIX . '/settings', [
            'site_name' => ['en' => 'Test'],
            'site_desc' => ['en' => 'Desc'],
            'meta_desc' => ['en' => 'Meta'],
            'site_copy_right' => ['en' => 'Copy'],
            'site_email' => 'test@example.com',
            'email_support' => 'support@example.com',
            'facebook' => 'https://facebook.com/test',
            'instagram' => 'https://instagram.com/test',
            'linkedin' => 'https://linkedin.com/test',
            'youtube' => 'https://youtube.com/test',
            'phone' => '1234567890',
            'fast_shipping_page_publish' => '1',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function user_without_permission_cannot_update_settings(): void
    {
        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/settings', [
            'site_name' => ['en' => 'Test'],
            'site_desc' => ['en' => 'Desc'],
            'meta_desc' => ['en' => 'Meta'],
            'site_copy_right' => ['en' => 'Copy'],
            'site_email' => 'test@example.com',
            'email_support' => 'support@example.com',
            'facebook' => 'https://facebook.com/test',
            'instagram' => 'https://instagram.com/test',
            'linkedin' => 'https://linkedin.com/test',
            'youtube' => 'https://youtube.com/test',
            'phone' => '1234567890',
            'fast_shipping_page_publish' => '1',
        ]);

        $response->assertStatus(403);
    }
}
