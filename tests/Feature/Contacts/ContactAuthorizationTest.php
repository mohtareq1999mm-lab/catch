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

class ContactAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function createUserWithPermissions(array $permissionNames): User
    {
        foreach ($permissionNames as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => 'custom_' . uniqid(),
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Custom Role']),
        ]);

        foreach ($permissionNames as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Custom User',
            'email' => uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function createContact(): Contact
    {
        return Contact::create([
            'name' => 'John',
            'email' => 'john@example.com',
            'subject' => 'Test Subject',
            'message' => 'Test message body text.',
        ]);
    }

    /** @test */
    public function anyone_can_create_contact_without_permissions(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts', [
            'name' => 'Public User',
            'email' => 'public@example.com',
            'subject' => 'Public Inquiry',
            'message' => 'This is a public message.',
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function user_with_view_contacts_can_index(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CONTACTS]);
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/contacts')->assertOk();
    }

    /** @test */
    public function user_without_view_contacts_cannot_index(): void
    {
        $user = $this->createUserWithPermissions([]);
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/contacts')->assertStatus(403);
    }

    /** @test */
    public function user_with_update_contact_can_show(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::UPDATE_CONTACT]);
        Sanctum::actingAs($user);
        $contact = $this->createContact();

        $this->getJson(self::PREFIX . "/contacts/{$contact->id}")->assertOk();
    }

    /** @test */
    public function user_without_update_contact_cannot_show(): void
    {
        $user = $this->createUserWithPermissions([]);
        Sanctum::actingAs($user);
        $contact = $this->createContact();

        $this->getJson(self::PREFIX . "/contacts/{$contact->id}")->assertStatus(403);
    }

    /** @test */
    public function user_with_update_contact_can_send_replay(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::UPDATE_CONTACT]);
        Sanctum::actingAs($user);
        $contact = $this->createContact();

        $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'Re: Test',
            'message' => 'This is a reply.',
        ])->assertOk();
    }

    /** @test */
    public function user_without_update_contact_cannot_send_replay(): void
    {
        $user = $this->createUserWithPermissions([]);
        Sanctum::actingAs($user);
        $contact = $this->createContact();

        $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'Re: Test',
            'message' => 'This is a reply.',
        ])->assertStatus(403);
    }

    /** @test */
    public function user_with_delete_contact_can_delete(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::DELETE_CONTACT]);
        Sanctum::actingAs($user);
        $contact = $this->createContact();

        $this->deleteJson(self::PREFIX . "/contacts/{$contact->id}")->assertOk();
    }

    /** @test */
    public function user_with_delete_contact_can_delete_all(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::DELETE_CONTACT]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all')->assertOk();
    }

    /** @test */
    public function user_with_delete_read_contacts_can_delete_all_read(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::DELETE_READ_CONTACTS]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all-read')->assertOk();
    }
}
