<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Models\ContentPage;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CmsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ContentPage table is created via migrations / RefreshDatabase
    }

    private function seedContentPermissions(): void
    {
        $guard = 'api';
        Permission::findOrCreate(PermissionEnum::VIEW_CONTENT_PAGES, $guard);
        Permission::findOrCreate(PermissionEnum::CREATE_CONTENT_PAGES, $guard);
        Permission::findOrCreate(PermissionEnum::UPDATE_CONTENT_PAGES, $guard);
        Permission::findOrCreate(PermissionEnum::DELETE_CONTENT_PAGES, $guard);
        $role = Role::firstOrCreate(
            ['name' => 'content_editor', 'guard_name' => $guard],
            ['display_name' => json_encode(['en' => 'Content Editor', 'ar' => 'محرر المحتوى'])]
        );
        $role->givePermissionTo([
            PermissionEnum::VIEW_CONTENT_PAGES,
            PermissionEnum::CREATE_CONTENT_PAGES,
            PermissionEnum::UPDATE_CONTENT_PAGES,
            PermissionEnum::DELETE_CONTENT_PAGES,
        ]);
    }

    private function makeEditorUser(): User
    {
        $this->seedContentPermissions();

        /** @var User $user */
        $user = User::create([
            'name' => 'Editor',
            'email' => 'editor@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->givePermissionTo([
            PermissionEnum::VIEW_CONTENT_PAGES,
            PermissionEnum::CREATE_CONTENT_PAGES,
            PermissionEnum::UPDATE_CONTENT_PAGES,
            PermissionEnum::DELETE_CONTENT_PAGES,
        ]);

        return $user;
    }

    public function test_public_can_fetch_page_by_slug_sorted_content(): void
    {
        // Public content-page via general route: /api/v1/general/content-pages/{slug}
        // Uses ContentPage with is_active=true and sections
        $page = ContentPage::create([
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/general/content-pages/home');

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'home');
        $response->assertJsonPath('data.title', 'Home');
    }

    public function test_editor_can_create_update_and_delete_page(): void
    {
        $user = $this->makeEditorUser();
        Sanctum::actingAs($user);

        // Create via admin route: POST /api/v1/content-pages (requires title array)
        $createPayload = [
            'title' => ['en' => 'Landing', 'ar' => 'هبوط'],
        ];

        $create = $this->postJson('/api/v1/content-pages', $createPayload);
        $create->assertCreated();
        $create->assertJsonPath('data.slug', 'landing');

        $pageId = $create['data']['id'];

        // Update
        $updatePayload = [
            'title' => ['en' => 'Updated Landing', 'ar' => 'هبوط محدث'],
            'is_active' => true,
        ];

        $update = $this->putJson("/api/v1/content-pages/{$pageId}", $updatePayload);
        $update->assertOk();
        $update->assertJsonPath('data.title', 'Updated Landing');

        // Delete
        $delete = $this->deleteJson("/api/v1/content-pages/{$pageId}");
        $delete->assertOk();
    }

    public function test_non_editor_cannot_mutate_pages(): void
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/content-pages', [
            'title' => ['en' => 'Blocked', 'ar' => 'محظور'],
        ]);

        $response->assertStatus(403);
    }
}

