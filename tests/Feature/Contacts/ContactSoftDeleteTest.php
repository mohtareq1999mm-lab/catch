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

class ContactSoftDeleteTest extends TestCase
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
    public function contact_uses_soft_deletes_trait(): void
    {
        $uses = class_uses_recursive(Contact::class);
        $this->assertTrue(in_array('Illuminate\Database\Eloquent\SoftDeletes', $uses));
    }

    /** @test */
    public function delete_soft_deletes_contact(): void
    {
        $contact = Contact::create([
            'name' => 'SoftDelete',
            'email' => 'soft@example.com',
            'subject' => 'Test',
            'message' => 'Test message.']);

        $this->deleteJson(self::PREFIX . "/contacts/{$contact->id}");

        $this->assertSoftDeleted($contact);
    }

    /** @test */
    public function deleted_contact_not_in_index(): void
    {
        $contact = Contact::create([
            'name' => 'Gone',
            'email' => 'gone@example.com',
            'subject' => 'Test',
            'message' => 'Test message.']);
        $contact->delete();

        $response = $this->getJson(self::PREFIX . '/contacts');

        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertNotContains($contact->id, $ids);
    }

    /** @test */
    public function show_returns_404_for_soft_deleted_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Hidden',
            'email' => 'hidden@example.com',
            'subject' => 'Test',
            'message' => 'Test message.']);
        $contact->delete();

        $this->getJson(self::PREFIX . "/contacts/{$contact->id}")->assertStatus(404);
    }

    /** @test */
    public function delete_all_soft_deletes_all_contacts(): void
    {
        $a = Contact::create(['name' => 'A', 'email' => 'a@test.com', 'subject' => 'S', 'message' => 'M']);
        $b = Contact::create(['name' => 'B', 'email' => 'b@test.com', 'subject' => 'S', 'message' => 'M']);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all');

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
    }

    /** @test */
    public function delete_all_read_contacts_soft_deletes_only_read_contacts(): void
    {
        $read = Contact::create(['name' => 'Read', 'email' => 'read@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => true]);
        $unread = Contact::create(['name' => 'Unread', 'email' => 'unread@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => false]);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all-read');

        $this->assertSoftDeleted($read);
        $this->assertNotSoftDeleted($unread);
    }

    /** @test */
    public function delete_all_keeps_records_in_database(): void
    {
        $a = Contact::create(['name' => 'A', 'email' => 'a@test.com', 'subject' => 'S', 'message' => 'M']);
        $b = Contact::create(['name' => 'B', 'email' => 'b@test.com', 'subject' => 'S', 'message' => 'M']);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all');

        $this->assertDatabaseHas('contacts', ['id' => $a->id]);
        $this->assertDatabaseHas('contacts', ['id' => $b->id]);
    }

    /** @test */
    public function delete_all_read_keeps_unread_records_in_database(): void
    {
        $read = Contact::create(['name' => 'Read', 'email' => 'read@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => true]);
        $unread = Contact::create(['name' => 'Unread', 'email' => 'unread@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => false]);

        $this->deleteJson(self::PREFIX . '/contacts/delete-all-read');

        $this->assertDatabaseHas('contacts', ['id' => $read->id]);
        $this->assertDatabaseHas('contacts', ['id' => $unread->id]);
    }
}
