<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Contact;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactValidationTest extends TestCase
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
        Permission::findOrCreate(PermissionEnum::VIEW_CONTACTS, self::GUARD);
        Permission::findOrCreate(PermissionEnum::UPDATE_CONTACT, self::GUARD);
        Permission::findOrCreate(PermissionEnum::DELETE_CONTACT, self::GUARD);
        Permission::findOrCreate(PermissionEnum::DELETE_READ_CONTACTS, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        $role->givePermissionTo([
            PermissionEnum::VIEW_CONTACTS,
            PermissionEnum::UPDATE_CONTACT,
            PermissionEnum::DELETE_CONTACT,
            PermissionEnum::DELETE_READ_CONTACTS]);

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
    public function create_returns_422_without_email(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'subject' => 'Test',
            'message' => 'Test message body.']);

        $response->assertStatus(422);
    }

    /** @test */
    public function create_returns_422_with_invalid_email(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'email' => 'not-an-email',
            'subject' => 'Test',
            'message' => 'Test message body.']);

        $response->assertStatus(422);
    }

    /** @test */
    public function create_returns_422_without_subject(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'email' => 'test@example.com',
            'message' => 'Test message body.']);

        $response->assertStatus(422);
    }

    /** @test */
    public function create_returns_422_without_message(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'email' => 'test@example.com',
            'subject' => 'Test']);

        $response->assertStatus(422);
    }

    /** @test */
    public function create_returns_422_with_short_message(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'email' => 'test@example.com',
            'subject' => 'Test',
            'message' => 'AB']);

        $response->assertStatus(422);
    }

    /** @test */
    public function create_returns_422_without_name(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'email' => 'test@example.com',
            'subject' => 'Test',
            'message' => 'A valid message body.']);

        $response->assertStatus(422);
    }
}
