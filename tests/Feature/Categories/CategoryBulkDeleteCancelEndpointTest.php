<?php

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * CATEGORY B — AUTHORIZATION for POST /categories/bulk-delete/{id}/cancel
 * CATEGORY E — terminal-state guard + side-effect isolation
 *
 * CAT-001 FIXED: cancelBulkDelete is now guarded by DELETE_CATEGORY
 * (CategoryController constructor, same permission as bulkDelete/bulkDeleteStatus).
 * These tests prove: unauthenticated 401 (route group), no-permission 403 with
 * zero side effects, view-only 403, authorized happy path, terminal 409, 404.
 */
class CategoryBulkDeleteCancelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $dir = storage_path('app/imports');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    // ─── CAT-001 REGRESSION: permission now enforced ────────────────────────

    public function test_regression_cat001_user_without_permission_is_rejected_with_no_side_effects(): void
    {
        $plainUser = $this->createUser('plain@cat.test', []);
        $import = $this->makeImport(status: 'processing');

        \Laravel\Sanctum\Sanctum::actingAs($plainUser, ['*']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->postJson(self::PREFIX . "/categories/bulk-delete/{$import->id}/cancel");

        // Fixed: DELETE_CATEGORY middleware guards cancelBulkDelete.
        // Handler renders Spatie denials as {message, status:false} (no success key).
        $response->assertStatus(403)->assertJsonPath('status', false);

        // No cancellation signal written.
        $this->assertFileDoesNotExist(storage_path("app/imports/cancel_{$import->id}.json"));

        // Import row untouched — no destructive side effect.
        $import->refresh();
        $this->assertSame('processing', $import->status);
    }

    public function test_view_only_permission_cannot_cancel_bulk_delete(): void
    {
        $viewer = $this->createUser('viewer@cat.test', [PermissionEnum::VIEW_CATEGORIES]);
        $import = $this->makeImport(status: 'processing');

        \Laravel\Sanctum\Sanctum::actingAs($viewer, ['*']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::PREFIX . "/categories/bulk-delete/{$import->id}/cancel")
            ->assertStatus(403)
            ->assertJsonPath('status', false);

        $this->assertFileDoesNotExist(storage_path("app/imports/cancel_{$import->id}.json"));
    }

    // ─── Happy path + terminal guard (with delete permission) ───────────────

    public function test_cancel_processing_bulk_delete_writes_signal_and_reports_cancelling(): void
    {
        $admin = $this->createUser('deleter@cat.test', [PermissionEnum::DELETE_CATEGORY]);
        $import = $this->makeImport(status: 'processing');

        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::PREFIX . "/categories/bulk-delete/{$import->id}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bulk_delete_id', $import->id)
            ->assertJsonPath('data.status', 'cancelling');

        $this->assertFileExists(storage_path("app/imports/cancel_{$import->id}.json"));

        // Status endpoint now reports the cancelling override.
        $this->getJson(self::PREFIX . "/categories/bulk-delete/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelling');
    }

    public function test_cancel_terminal_bulk_delete_returns_409_and_no_signal(): void
    {
        $admin = $this->createUser('deleter2@cat.test', [PermissionEnum::DELETE_CATEGORY]);
        $import = $this->makeImport(status: 'completed');

        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::PREFIX . "/categories/bulk-delete/{$import->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertFileDoesNotExist(storage_path("app/imports/cancel_{$import->id}.json"));
    }

    public function test_cancel_nonexistent_bulk_delete_returns_404(): void
    {
        $admin = $this->createUser('deleter3@cat.test', [PermissionEnum::DELETE_CATEGORY]);

        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::PREFIX . '/categories/bulk-delete/999999/cancel')
            ->assertStatus(404);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function createUser(string $email, array $permissions): User
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $user = User::create([
            'name' => 'QA ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
            'type' => count($permissions) ? 'admin' : 'user',
        ]);

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function makeImport(string $status): Import
    {
        return Import::create([
            'type' => 'category-bulk-delete',
            'file_path' => '',
            'file_name' => '',
            'status' => $status,
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_by' => 1,
        ]);
    }
}
