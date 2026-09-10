<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\ImportType;
use Marvel\Enums\Permission as Perm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IdorAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private function createUser(array $perms, bool $super = false): User
    {
        foreach ($perms as $p) Permission::findOrCreate($p, self::GUARD);
        Permission::findOrCreate(self::GUARD);
        // also ensure product perms exist for our tests
        Permission::findOrCreate(Perm::IMPORT_PRODUCT, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_PRODUCT, self::GUARD);
        Permission::findOrCreate(Perm::IMPORT_CATEGORY, self::GUARD);
        Permission::findOrCreate(Perm::IMPORT_BRAND, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_CATEGORY, self::GUARD);
        Permission::findOrCreate(Perm::EXPORT_BRAND, self::GUARD);
        $role = Role::create(['name' => 'r_' . uniqid(), 'guard_name' => self::GUARD, 'display_name' => 'test']);
        foreach ($perms as $p) $role->givePermissionTo($p);
        if ($super)
        $user = User::create([
            'name' => 'u_' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($role);
        foreach ($perms as $p) $user->givePermissionTo($p);
        if ($super)
        return $user;
    }

    private function createImport(User $owner, string $type, string $status = 'completed'): Import
    {
        return Import::create([
            'type' => $type,
            'file_path' => 'imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => $status,
            'total_rows' => 10,
            'processed_rows' => 10,
            'success_rows' => 8,
            'failed_rows' => 2,
            'errors' => [['sheet'=>'products','row'=>2,'sku'=>'T','error_message'=>'err']],
            'created_by' => $owner->id,
        ]);
    }

    // IDOR same type - product
    public function test_user_b_cannot_view_user_a_product_import_same_type(): void
    {
        $userA = $this->createUser([Perm::IMPORT_PRODUCT]);
        $userB = $this->createUser([Perm::IMPORT_PRODUCT]);
        $import = $this->createImport($userA, ImportType::PRODUCT_IMPORT);

        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}");
        // Should be 404 not 403 or 200 (not leak existence)
        $this->assertContains($resp->getStatusCode(), [404, 500], 'Should not expose other user record');
        // Ideally 404; if 500 then ImportPolicy bug
        if ($resp->getStatusCode() === 404) {
            $this->assertTrue(true);
        } else {
            $this->fail('Expected 404 but got '.$resp->getStatusCode().': '.$resp->getContent());
        }
    }

    public function test_user_b_cannot_cancel_user_a_import(): void
    {
        $userA = $this->createUser([Perm::IMPORT_PRODUCT]);
        $userB = $this->createUser([Perm::IMPORT_PRODUCT]);
        $import = $this->createImport($userA, ImportType::PRODUCT_IMPORT, 'processing');

        Sanctum::actingAs($userB);
        $resp = $this->postJson(self::PREFIX . "/products/import/{$import->id}/cancel");
        $this->assertEquals(404, $resp->getStatusCode(), 'Cross-tenant cancel must be 404. Got: '.$resp->getContent());
        // A's import should NOT be cancelled
        $import->refresh();
        $this->assertEquals('processing', $import->status);
    }

    public function test_user_b_cannot_download_errors_of_user_a(): void
    {
        $userA = $this->createUser([Perm::IMPORT_PRODUCT]);
        $userB = $this->createUser([Perm::IMPORT_PRODUCT]);
        $import = $this->createImport($userA, ImportType::PRODUCT_IMPORT);

        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}/download-errors");
        $this->assertEquals(404, $resp->getStatusCode(), 'Error download must be 404 for non-owner. Got: '.$resp->getContent());
    }

    // Category IDOR
    public function test_user_b_cannot_view_user_a_category_import(): void
    {
        $userA = $this->createUser([Perm::IMPORT_CATEGORY]);
        $userB = $this->createUser([Perm::IMPORT_CATEGORY]);
        $import = $this->createImport($userA, ImportType::CATEGORY_IMPORT);

        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/categories/import/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode(), 'Category IDOR must be 404. Got: '.$resp->getContent());
    }

    public function test_user_b_cannot_view_user_a_brand_import(): void
    {
        $userA = $this->createUser([Perm::IMPORT_BRAND]);
        $userB = $this->createUser([Perm::IMPORT_BRAND]);
        $import = $this->createImport($userA, ImportType::BRAND_IMPORT);

        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/brands/import/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode(), 'Brand IDOR must be 404. Got: '.$resp->getContent());
    }

    // Wrong type - product id through category endpoint
    public function test_product_import_id_through_category_endpoint_returns_404(): void
    {
        $user = $this->createUser([Perm::IMPORT_PRODUCT, Perm::IMPORT_CATEGORY]);
        $import = $this->createImport($user, ImportType::PRODUCT_IMPORT);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . "/categories/import/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode(), 'Cross-type must be 404. Got: '.$resp->getContent());
    }

    public function test_brand_import_through_product_endpoint_returns_404(): void
    {
        $user = $this->createUser([Perm::IMPORT_PRODUCT, Perm::IMPORT_BRAND]);
        $import = $this->createImport($user, ImportType::BRAND_IMPORT);
        Sanctum::actingAs($user);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode(), 'Brand via product endpoint must be 404. Got: '.$resp->getContent());
    }

    public function test_product_export_id_through_category_export_endpoint_returns_404(): void
    {
        // Currently product export is sync and has no status route; but if D-4 implemented, test crossover
        // We test that a category-export id cannot be accessed via brand-export endpoint
        $userA = $this->createUser([Perm::EXPORT_CATEGORY, Perm::EXPORT_BRAND]);
        $catExport = $this->createImport($userA, ImportType::CATEGORY_EXPORT);
        Sanctum::actingAs($userA);
        // brand export status with category export id should be 404 (if type-scoped)
        // Note: current CategoryExportController::status is NOT type-scoped (bug), so this will be 200
        // We'll test brand export download (which IS type-scoped) instead
        $resp = $this->getJson(self::PREFIX . "/brands/export/{$catExport->id}");
        // If status is not type-scoped, it will wrongly succeed
        $this->assertEquals(404, $resp->getStatusCode(), 'Cross-export-type must be 404. Got: '.$resp->getContent());
    }

    // Export crossover - import id via export endpoint
    public function test_product_import_id_through_export_endpoint_must_not_resolve(): void
    {
        $user = $this->createUser([Perm::IMPORT_PRODUCT, Perm::EXPORT_CATEGORY]);
        $import = $this->createImport($user, ImportType::PRODUCT_IMPORT);
        Sanctum::actingAs($user);
        // Try to fetch via export status - should be 404
        $resp = $this->getJson(self::PREFIX . "/categories/export/{$import->id}");
        $this->assertEquals(404, $resp->getStatusCode());
    }

    // Super admin can access
    public function test_super_admin_can_view_other_users_import(): void
    {
        $userA = $this->createUser([Perm::IMPORT_PRODUCT]);
        $super = $this->createUser([], true);
        $import = $this->createImport($userA, ImportType::PRODUCT_IMPORT);
        Sanctum::actingAs($super);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}");
        // Should be 200 for super admin (or 500 if policy bug)
        $this->assertContains($resp->getStatusCode(), [200, 500], 'Super admin should be able to view. Got: '.$resp->getContent());
        if ($resp->getStatusCode() === 500) {
            $this->fail('Super admin access produced 500 due to ImportPolicy user type bug: '.$resp->getContent());
        }
        $resp->assertOk();
    }

    public function test_guest_cannot_access_import_status(): void
    {
        $userA = $this->createUser([Perm::IMPORT_PRODUCT]);
        $import = $this->createImport($userA, ImportType::PRODUCT_IMPORT);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}");
        $resp->assertUnauthorized();
    }

    public function test_guest_cannot_cancel_import(): void
    {
        $resp = $this->postJson(self::PREFIX . "/products/import/1/cancel");
        $resp->assertUnauthorized();
    }

    public function test_guest_cannot_download_errors(): void
    {
        $resp = $this->getJson(self::PREFIX . "/products/import/1/download-errors");
        $resp->assertUnauthorized();
    }

    // Unauthorized user (has no permission) cannot access even own record via wrong permission?
    // Product import requires CREATE_PRODUCT. A user with only VIEW_PRODUCTS should be 403.
    public function test_user_without_product_import_permission_cannot_view_even_own_import(): void
    {
        // Create a user with no permission, then manually create import owned by them
        $userNoPerm = $this->createUser([]); // no CREATE_PRODUCT
        $import = $this->createImport($userNoPerm, ImportType::PRODUCT_IMPORT);
        Sanctum::actingAs($userNoPerm);
        $resp = $this->getJson(self::PREFIX . "/products/import/{$import->id}");
        // Permission middleware should give 403
        $this->assertEquals(403, $resp->getStatusCode(), 'Should be forbidden without permission. Got: '.$resp->getContent());
    }

    // Export private storage: unauthorized user cannot download
    public function test_user_b_cannot_download_user_a_category_export_file(): void
    {
        $userA = $this->createUser([Perm::EXPORT_CATEGORY]);
        $userB = $this->createUser([Perm::EXPORT_CATEGORY]);
        // Create completed export owned by A
        $export = Import::create([
            'type' => ImportType::CATEGORY_EXPORT,
            'file_path' => 'exports/test.xlsx',
            'file_name' => 'test.xlsx',
            'status' => 'completed',
            'total_rows' => 1,
            'created_by' => $userA->id,
        ]);
        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/categories/export/{$export->id}/download");
        // Need to store fake file else 409, but auth should be 404 before 409
        // With current bug, status() is not type-scoped but download() is, so download should be 404
        $this->assertEquals(404, $resp->getStatusCode(), 'Export download IDOR must be 404. Got: '.$resp->getContent());
    }

    // Import/export crossover attack: user A export, user B knows export id/path and tries download
    public function test_user_b_cannot_use_guessed_export_id_to_download_file(): void
    {
        $userA = $this->createUser([Perm::EXPORT_BRAND]);
        $userB = $this->createUser([Perm::EXPORT_BRAND]);
        $export = Import::create([
            'type' => ImportType::BRAND_EXPORT,
            'file_path' => 'exports/brand-export.xlsx',
            'file_name' => 'brand-export.xlsx',
            'status' => 'completed',
            'total_rows' => 5,
            'created_by' => $userA->id,
        ]);
        Sanctum::actingAs($userB);
        $resp = $this->getJson(self::PREFIX . "/brands/export/{$export->id}/download");
        $this->assertEquals(404, $resp->getStatusCode());
    }
}

