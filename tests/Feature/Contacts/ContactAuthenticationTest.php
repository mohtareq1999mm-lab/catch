<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Contact;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactAuthenticationTest extends TestCase
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
    public function unauthenticated_user_cannot_index_contacts(): void
    {
        $response = $this->getJson(self::PREFIX . '/contacts');

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_show_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'subject' => 'Test',
            'message' => 'Test message body']);

        $response = $this->getJson(self::PREFIX . "/contacts/{$contact->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'subject' => 'Test',
            'message' => 'Test message body']);

        $response = $this->deleteJson(self::PREFIX . "/contacts/{$contact->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_all_contacts(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/contacts/delete-all');

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_all_read_contacts(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/contacts/delete-all-read');

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_send_replay(): void
    {
        $contact = Contact::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'subject' => 'Test',
            'message' => 'Test message body']);

        $response = $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'Re: Test',
            'message' => 'Reply body']);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_can_create_contact(): void
    {
        Notification::fake();

        $response = $this->postJson(self::PREFIX . '/contacts', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Inquiry',
            'message' => 'I have a question about your service.']);

        $response->assertStatus(201);
    }

    /** @test */
    public function authenticated_user_can_access_all_routes(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::PREFIX . '/contacts')->assertOk();
    }
}
