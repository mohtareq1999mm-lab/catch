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

class ContactCrudTest extends TestCase
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
    public function can_list_contacts(): void
    {
        Contact::create(['name' => 'A', 'email' => 'a@test.com', 'subject' => 'S1', 'message' => 'M1']);
        Contact::create(['name' => 'B', 'email' => 'b@test.com', 'subject' => 'S2', 'message' => 'M2']);

        $response = $this->getJson(self::PREFIX . '/contacts');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.data');
    }

    /** @test */
    public function can_show_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'subject' => 'Help',
            'message' => 'Need assistance.']);

        $response = $this->getJson(self::PREFIX . "/contacts/{$contact->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $contact->id);
    }

    /** @test */
    public function show_marks_contact_as_read(): void
    {
        $contact = Contact::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'subject' => 'Support',
            'message' => 'Need help.']);

        $this->assertFalse((bool) $contact->is_read);

        $this->getJson(self::PREFIX . "/contacts/{$contact->id}");

        $contact->refresh();
        $this->assertTrue((bool) $contact->is_read);
    }

    /** @test */
    public function can_delete_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'subject' => 'Delete',
            'message' => 'Delete me.']);

        $response = $this->deleteJson(self::PREFIX . "/contacts/{$contact->id}");

        $response->assertOk();
        $this->assertSoftDeleted($contact);
    }

    /** @test */
    public function show_returns_404_for_nonexistent_contact(): void
    {
        $response = $this->getJson(self::PREFIX . '/contacts/99999');

        $response->assertStatus(404);
    }

    /** @test */
    public function delete_returns_404_for_nonexistent_contact(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/contacts/99999');

        $response->assertStatus(404);
    }
}
