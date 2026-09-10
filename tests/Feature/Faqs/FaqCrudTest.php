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

class FaqCrudTest extends TestCase
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
        Permission::findOrCreate(PermissionEnum::VIEW_FAQS, self::GUARD);
        Permission::findOrCreate(PermissionEnum::CREATE_FAQ, self::GUARD);
        Permission::findOrCreate(PermissionEnum::UPDATE_FAQ, self::GUARD);
        Permission::findOrCreate(PermissionEnum::DELETE_FAQ, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        $role->givePermissionTo([
            PermissionEnum::VIEW_FAQS,
            PermissionEnum::CREATE_FAQ,
            PermissionEnum::UPDATE_FAQ,
            PermissionEnum::DELETE_FAQ]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        $user->assignRole($role);

        return $user;
    }

    /** @test */
    public function can_create_faq(): void
    {
        $response = $this->postJson(self::PREFIX . '/faqs', [
            'faq_title' => ['en' => 'How to return a product?'],
            'faq_description' => ['en' => 'You can return any product within 30 days.']]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('faqs', [
            'id' => $response->json('data.id')]);
    }

    /** @test */
    public function can_list_faqs(): void
    {
        Faqs::create(['faq_title' => ['en' => 'FAQ One'], 'faq_description' => ['en' => 'Desc One']]);
        Faqs::create(['faq_title' => ['en' => 'FAQ Two'], 'faq_description' => ['en' => 'Desc Two']]);

        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data.data');
    }

    /** @test */
    public function can_show_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Specific FAQ'],
            'faq_description' => ['en' => 'Specific description']]);

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $faq->id);
    }

    /** @test */
    public function can_update_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Original Title'],
            'faq_description' => ['en' => 'Original description']]);

        $response = $this->putJson(self::PREFIX . "/faqs/{$faq->id}", [
            'faq_title' => ['en' => 'Updated Title']]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('faqs', ['id' => $faq->id]);
    }

    /** @test */
    public function can_delete_faq(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'To Delete'],
            'faq_description' => ['en' => 'Will be deleted']]);

        $response = $this->deleteJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted($faq);
    }

    /** @test */
    public function show_returns_404_for_nonexistent_faq(): void
    {
        $response = $this->getJson(self::PREFIX . '/faqs/99999');

        $response->assertStatus(404);
    }

    /** @test */
    public function delete_returns_404_for_nonexistent_faq(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/faqs/99999');

        $response->assertStatus(404);
    }
}
