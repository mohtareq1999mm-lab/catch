<?php

declare(strict_types=1);

namespace Tests\Feature\Faqs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Faqs;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FaqAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->user = $this->createAuthenticatedUser();
    }

    private function createAuthenticatedUser(): User
    {
        $permissions = [
            PermissionEnum::VIEW_FAQS,
            PermissionEnum::CREATE_FAQ,
            PermissionEnum::UPDATE_FAQ,
            PermissionEnum::DELETE_FAQ];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        $user->assignRole($role);

        return $user;
    }

    /** @test */
    public function unauthenticated_user_cannot_index_faqs(): void
    {
        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_show_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Test FAQ'],
            'faq_description' => ['en' => 'Test description']]);

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_faq(): void
    {
        $response = $this->postJson(self::PREFIX . '/faqs', [
            'faq_title' => ['en' => 'How to return?'],
            'faq_description' => ['en' => 'Return policy details.']]);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_update_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Test FAQ'],
            'faq_description' => ['en' => 'Test description']]);

        $response = $this->putJson(self::PREFIX . "/faqs/{$faq->id}", [
            'faq_title' => ['en' => 'Updated FAQ']]);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Test FAQ'],
            'faq_description' => ['en' => 'Test description']]);

        $response = $this->deleteJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_reorder_faqs(): void
    {
        $response = $this->putJson(self::PREFIX . '/faqs/reorder', [
            'faqs' => [1, 2, 3]]);

        $response->assertStatus(401);
    }

    /** @test */
    public function authenticated_user_can_access_all_routes(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::PREFIX . '/faqs')->assertOk();
    }
}
