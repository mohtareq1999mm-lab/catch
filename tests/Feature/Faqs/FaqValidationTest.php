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

class FaqValidationTest extends TestCase
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
    public function update_accepts_partial_data(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Original'],
            'faq_description' => ['en' => 'Original description']]);

        $response = $this->putJson(self::PREFIX . "/faqs/{$faq->id}", [
            'faq_title' => ['en' => 'Only Title Updated']]);

        $response->assertOk();
        $this->assertEquals('Original description', $faq->fresh()->getTranslation('faq_description', 'en'));
    }

    /** @test */
    public function update_accepts_status_field(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Status Test'],
            'faq_description' => ['en' => 'Status description']]);

        $response = $this->putJson(self::PREFIX . "/faqs/{$faq->id}", [
            'status' => 0]);

        $response->assertOk();
        $this->assertEquals(0, $faq->fresh()->status);
    }

    /** @test */
    public function update_allows_same_title_for_self(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Self Title'],
            'faq_description' => ['en' => 'Description']]);

        $response = $this->putJson(self::PREFIX . "/faqs/{$faq->id}", [
            'faq_title' => ['en' => 'Self Title']]);

        $response->assertOk();
    }
}
