<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsCrudTest extends TestCase
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

    /** @test */
    public function can_view_settings(): void
    {
        Settings::create([
            'site_name' => json_encode(['en' => 'Test Site']),
            'options' => ['currency' => 'USD', 'siteTitle' => 'Test']]);

        $response = $this->getJson(self::PREFIX . '/settings');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function can_update_settings(): void
    {
        Storage::fake('public');

        $setting = Settings::create([
            'site_name' => json_encode(['en' => 'Old Name']),
            'site_desc' => json_encode(['en' => 'Old description']),
            'meta_desc' => json_encode(['en' => 'Old meta']),
            'site_copy_right' => json_encode(['en' => 'Old copyright']),
            'site_email' => 'old@example.com',
            'email_support' => 'support@example.com',
            'facebook' => 'https://facebook.com/old',
            'instagram' => 'https://instagram.com/old',
            'linkedin' => 'https://linkedin.com/old',
            'youtube' => 'https://youtube.com/old',
            'tiktok' => 'https://tiktok.com/old',
            'snapchat' => 'https://snapchat.com/old',
            'phone' => '1234567890',
            'fast_shipping_page_publish' => true,
            'options' => ['currency' => 'USD']]);

        $response = $this->putJson(self::PREFIX . '/settings', [
            'site_name' => ['en' => 'New Name'],
            'site_desc' => ['en' => 'New description'],
            'meta_desc' => ['en' => 'New meta'],
            'site_copy_right' => ['en' => 'New copyright'],
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
            'site_email' => 'new@example.com',
            'email_support' => 'support@example.com',
            'facebook' => 'https://facebook.com/new',
            'instagram' => 'https://instagram.com/new',
            'linkedin' => 'https://linkedin.com/new',
            'youtube' => 'https://youtube.com/new',
            'tiktok' => 'https://tiktok.com/new',
            'snapchat' => 'https://snapchat.com/new',
            'phone' => '0987654321',
            'fast_shipping_page_publish' => '1']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertEquals('New Name', $setting->refresh()->getTranslation('site_name', 'en'));
        $this->assertEquals('https://tiktok.com/new', $setting->refresh()->tiktok);
        $this->assertEquals('https://snapchat.com/new', $setting->refresh()->snapchat);
    }

    /** @test */
    public function settings_returns_expected_json_structure(): void
    {
        Settings::create([
            'site_name' => json_encode(['en' => 'Test Site']),
            'options' => ['currency' => 'USD']]);

        $response = $this->getJson(self::PREFIX . '/settings');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'site_name',
                'site_desc',
                'meta_desc',
                'site_copy_right',
                'logo',
                'favicon',
                'site_email',
                'email_support',
                'facebook',
                'instagram',
                'linkedin',
                'promotion_video_url',
                'youtube',
                'tiktok',
                'snapchat',
                'phone',
                'fast_shipping_page_publish',
                'options']]);
    }

    /** @test */
    public function omitted_tiktok_and_snapchat_preserve_existing_values(): void
    {
        $setting = Settings::create([
            'site_name' => json_encode(['en' => 'Test Site']),
            'tiktok' => 'https://tiktok.com/old',
            'snapchat' => 'https://snapchat.com/old',
            'options' => ['currency' => 'USD']]);

        $response = $this->putJson(self::PREFIX . '/settings', [
            'site_name' => ['en' => 'Updated Site'],
            'fast_shipping_page_publish' => '1']);

        $response->assertOk();

        $this->assertEquals('https://tiktok.com/old', $setting->refresh()->tiktok);
        $this->assertEquals('https://snapchat.com/old', $setting->refresh()->snapchat);
    }

    /** @test */
    public function updated_tiktok_and_snapchat_are_returned_by_admin_and_website_endpoints(): void
    {
        Settings::create([
            'site_name' => json_encode(['en' => 'Test Site']),
            'options' => ['currency' => 'USD']]);

        $updateResponse = $this->putJson(self::PREFIX . '/settings', [
            'site_name' => ['en' => 'Updated Site'],
            'tiktok' => 'https://tiktok.com/meem',
            'snapchat' => 'https://snapchat.com/meem',
            'facebook' => 'https://facebook.com/meem',
            'instagram' => 'https://instagram.com/meem',
            'linkedin' => 'https://linkedin.com/meem',
            'youtube' => 'https://youtube.com/meem',
            'fast_shipping_page_publish' => '1']);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.tiktok', 'https://tiktok.com/meem');
        $updateResponse->assertJsonPath('data.snapchat', 'https://snapchat.com/meem');

        $adminResponse = $this->getJson(self::PREFIX . '/settings');
        $adminResponse->assertOk();
        $adminResponse->assertJsonPath('data.tiktok', 'https://tiktok.com/meem');
        $adminResponse->assertJsonPath('data.snapchat', 'https://snapchat.com/meem');

        $websiteResponse = $this->getJson(self::PREFIX . '/general/settings');
        $websiteResponse->assertOk();
        $websiteResponse->assertJsonPath('data.tiktok', 'https://tiktok.com/meem');
        $websiteResponse->assertJsonPath('data.snapchat', 'https://snapchat.com/meem');
    }
}
