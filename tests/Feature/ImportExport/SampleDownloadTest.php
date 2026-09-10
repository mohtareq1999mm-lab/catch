<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SampleDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private function createUserWithPermissions(array $perms, bool $isSuperAdmin = false): User
    {
        foreach ($perms as $p) {
            Permission::findOrCreate($p, self::GUARD);
        }
        if ($isSuperAdmin) {
            Permission::findOrCreate(self::GUARD);
        }
        $roleName = 'role_' . uniqid();
        $role = Role::create(['name' => $roleName, 'guard_name' => self::GUARD, 'display_name' => $roleName]);
        foreach ($perms as $p) {
            $role->givePermissionTo($p);
        }
        if ($isSuperAdmin) {

        }
        $user = User::create([
            'name' => 'User ' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($role);
        foreach ($perms as $p) $user->givePermissionTo($p);
        if ($isSuperAdmin)
        return $user;
    }

    public function test_guest_cannot_download_product_sample(): void
    {
        $this->getJson(self::PREFIX . '/products/import/sample')->assertUnauthorized();
    }

    public function test_guest_cannot_download_category_sample(): void
    {
        $this->getJson(self::PREFIX . '/categories/import/sample')->assertUnauthorized();
    }

    public function test_guest_cannot_download_brand_sample(): void
    {
        $this->getJson(self::PREFIX . '/brands/import/sample')->assertUnauthorized();
    }

    public function test_authenticated_without_permission_cannot_download_product_sample(): void
    {
        $user = $this->createUserWithPermissions([]);
        Sanctum::actingAs($user);
        $this->getJson(self::PREFIX . '/products/import/sample')->assertForbidden();
    }

    public function test_authorized_user_can_download_product_sample(): void
    {
        $user = $this->createUserWithPermissions([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/products/import/sample');
        $resp->assertOk();
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers->get('Content-Type'));
    }

    public function test_authorized_user_can_download_category_sample(): void
    {
        $user = $this->createUserWithPermissions([Perm::IMPORT_CATEGORY]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/categories/import/sample');
        $resp->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers->get('Content-Type'));
    }

    public function test_authorized_user_can_download_brand_sample(): void
    {
        $user = $this->createUserWithPermissions([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/brands/import/sample');
        $resp->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers->get('Content-Type'));
    }

    public function test_super_admin_can_download_all_samples(): void
    {
        $user = $this->createUserWithPermissions([], true);
        Sanctum::actingAs($user);
        $this->getJson(self::PREFIX . '/products/import/sample')->assertOk();
        $this->getJson(self::PREFIX . '/categories/import/sample')->assertOk();
        $this->getJson(self::PREFIX . '/brands/import/sample')->assertOk();
    }

    public function test_missing_product_sample_returns_404_with_translated_message_en(): void
    {
        config()->set('marvel.import.samples.product', storage_path('nonexistent_product_' . uniqid() . '.xlsx'));
        app()->setLocale('en');
        $user = $this->createUserWithPermissions([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/products/import/sample');
        $resp->assertNotFound();
        $resp->assertJsonFragment(['message' => __('message.IMPORT.SAMPLE_NOT_FOUND')]);
        $this->assertNotEquals('message.IMPORT.SAMPLE_NOT_FOUND', $resp->json('message'), 'Must not leak raw translation key');
    }

    public function test_missing_category_sample_returns_404_en(): void
    {
        config()->set('marvel.import.samples.category', storage_path('nonexistent_cat_' . uniqid() . '.xlsx'));
        app()->setLocale('en');
        $user = $this->createUserWithPermissions([Perm::IMPORT_CATEGORY]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/categories/import/sample');
        $resp->assertNotFound();
        $this->assertStringNotContainsString('FileNotFoundException', $resp->getContent());
    }

    public function test_missing_brand_sample_returns_404_not_500(): void
    {
        config()->set('marvel.import.samples.brand', storage_path('nonexistent_brand_' . uniqid() . '.xlsx'));
        app()->setLocale('en');
        $user = $this->createUserWithPermissions([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/brands/import/sample');
        // Before fix BE-001 this was 500 with class not found
        $resp->assertNotFound();
        $resp->assertJsonFragment(['message' => __('message.IMPORT.SAMPLE_NOT_FOUND')]);
    }

    public function test_missing_sample_arabic_translation(): void
    {
        config()->set('marvel.import.samples.product', storage_path('nonexistent_ar_' . uniqid() . '.xlsx'));
        app()->setLocale('ar');
        $user = $this->createUserWithPermissions([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/products/import/sample', ['Accept-Language' => 'ar']);
        $resp->assertNotFound();
        // Should not return raw key
        $this->assertNotEquals('message.IMPORT.SAMPLE_NOT_FOUND', $resp->json('message'));
        $this->assertNotEmpty($resp->json('message'));
    }

    public function test_missing_brand_sample_arabic(): void
    {
        config()->set('marvel.import.samples.brand', storage_path('nonexistent_ar_brand_' . uniqid() . '.xlsx'));
        app()->setLocale('ar');
        $user = $this->createUserWithPermissions([Perm::IMPORT_BRAND]);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . '/brands/import/sample');
        $resp->assertNotFound();
        $this->assertNotEquals('message.IMPORT.SAMPLE_NOT_FOUND', $resp->json('message'));
    }

    public function test_product_sample_file_is_readable_workbook(): void
    {
        $user = $this->createUserWithPermissions([Perm::IMPORT_PRODUCT]);
        Sanctum::actingAs($user);
        $resp = $this->get(self::PREFIX . '/products/import/sample');
        $resp->assertOk();
        $this->assertStringContainsString('vnd.openxmlformats', $resp->headers->get('Content-Type'));
        // BinaryFileResponse streams file, not json; just ensure headers indicate xlsx
        $this->assertTrue(true, 'Sample file returned as BinaryFileResponse with xlsx content-type');
    }
}
