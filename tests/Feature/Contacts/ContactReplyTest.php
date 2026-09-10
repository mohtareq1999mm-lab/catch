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

class ContactReplyTest extends TestCase
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
    public function can_send_reply(): void
    {
        $contact = Contact::create([
            'name' => 'Original',
            'email' => 'original@example.com',
            'subject' => 'Original Subject',
            'message' => 'Original message body.']);

        $response = $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'RE: Original Subject',
            'message' => 'Thank you for your inquiry.']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function reply_creates_new_contact_record_with_same_email(): void
    {
        $contact = Contact::create([
            'name' => 'Sam',
            'email' => 'sam@example.com',
            'subject' => 'Question',
            'message' => 'I have a question.']);

        $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'RE: Question',
            'message' => 'Here is your answer.']);

        $replies = Contact::where('email', 'sam@example.com')->get();
        $this->assertCount(2, $replies);
    }

    /** @test */
    public function reply_record_is_marked_as_read_and_replayed(): void
    {
        $contact = Contact::create([
            'name' => 'ReplyUser',
            'email' => 'reply@example.com',
            'subject' => 'Topic',
            'message' => 'Message content.']);

        $response = $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'RE: Topic',
            'message' => 'Reply content.']);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.is_read'));
        $this->assertTrue((bool) $response->json('data.is_replay'));
    }

    /** @test */
    public function reply_returns_404_for_nonexistent_contact(): void
    {
        $response = $this->postJson(self::PREFIX . '/contacts/99999/reply', [
            'subject' => 'RE: Missing',
            'message' => 'Reply to missing contact.']);

        $response->assertStatus(404);
    }

    /** @test */
    public function original_contact_is_not_marked_as_read_or_replayed(): void
    {
        $contact = Contact::create([
            'name' => 'Original',
            'email' => 'original@example.com',
            'subject' => 'Original',
            'message' => 'Original message.']);

        $this->postJson(self::PREFIX . "/contacts/{$contact->id}/reply", [
            'subject' => 'RE: Original',
            'message' => 'Reply.']);

        $contact->refresh();
        $this->assertFalse((bool) $contact->is_read);
        $this->assertFalse((bool) $contact->is_replay);
    }
}
