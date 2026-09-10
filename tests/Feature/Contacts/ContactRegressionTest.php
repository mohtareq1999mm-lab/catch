<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Events\ContactMessageReceived;
use App\Notifications\NewContactMessageNotification;
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

class ContactRegressionTest extends TestCase
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
    public function b1_soft_delete_does_not_hard_delete(): void
    {
        $contact = Contact::create([
            'name' => 'B1',
            'email' => 'b1@example.com',
            'subject' => 'B1',
            'message' => 'B1 message.']);

        $contact->delete();

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    /** @test */
    public function b2_contact_uses_soft_deletes(): void
    {
        $uses = class_uses_recursive(Contact::class);
        $this->assertTrue(in_array('Illuminate\Database\Eloquent\SoftDeletes', $uses));
    }

    /** @test */
    public function b3_store_creates_contact_successfully(): void
    {
        Notification::fake();

        $response = $this->postJson(self::PREFIX . '/contacts', [
            'name' => 'Public',
            'email' => 'public@example.com',
            'subject' => 'Public',
            'message' => 'Public message.']);

        $response->assertStatus(201);
    }

    /** @test */
    public function b4_contact_us_route_works(): void
    {
        Notification::fake();

        $response = $this->postJson(self::PREFIX . '/contact-us', [
            'name' => 'ContactUs',
            'email' => 'contactus@example.com',
            'subject' => 'Contact Us',
            'message' => 'Contact us message.']);

        $response->assertStatus(201);
    }

    /** @test */
    public function b5_index_filters_by_read(): void
    {
        Contact::create(['name' => 'Read', 'email' => 'r@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => true]);
        Contact::create(['name' => 'Unread', 'email' => 'u@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => false]);

        $response = $this->getJson(self::PREFIX . '/contacts?read=true');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue((bool) $response->json('data.data.0.is_read'));
    }

    /** @test */
    public function b6_index_filters_by_unread(): void
    {
        Contact::create(['name' => 'Read', 'email' => 'r@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => true]);
        Contact::create(['name' => 'Unread', 'email' => 'u@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => false]);

        $response = $this->getJson(self::PREFIX . '/contacts?unread=true');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertFalse((bool) $response->json('data.data.0.is_read'));
    }

    /** @test */
    public function b7_index_filters_by_replay(): void
    {
        Contact::create(['name' => 'Replied', 'email' => 'r@test.com', 'subject' => 'S', 'message' => 'M', 'is_replay' => true]);
        Contact::create(['name' => 'NotReplied', 'email' => 'n@test.com', 'subject' => 'S', 'message' => 'M', 'is_replay' => false]);

        $response = $this->getJson(self::PREFIX . '/contacts?replay=true');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    /** @test */
    public function b8_translation_keys_exist(): void
    {
        $expectedKeys = [
            'MESSAGE.CONTACT_CREATED_SUCCESSFULLY',
            'MESSAGE.REPLAY_SENT_SUCCESSFULLY',
            'MESSAGE.CONTACT_DELETED_SUCCESSFULLY',
            'MESSAGE.ALL_CONTACTS_DELETED_SUCCESSFULLY',
            'MESSAGE.ALL_READ_CONTACTS_DELETED_SUCCESSFULLY'];

        $arMessages = include resource_path('lang/ar/message.php');
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $arMessages, "Arabic translation missing for $key");
        }
    }

    /** @test */
    public function b9_read_and_unread_filters_are_exclusive(): void
    {
        Contact::create(['name' => 'Mixed', 'email' => 'm@test.com', 'subject' => 'S', 'message' => 'M', 'is_read' => true]);

        $response = $this->getJson(self::PREFIX . '/contacts?read=true&unread=true');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    /** @test */
    public function b10_contact_message_received_triggers_admin_notification(): void
    {
        Notification::fake();

        $contact = Contact::create([
            'name' => 'Test',
            'email' => 'test@test.com',
            'subject' => 'Subject',
            'message' => 'Test message body.']);

        ContactMessageReceived::dispatch($contact);

        Notification::assertSentTo(
            $this->user,
            NewContactMessageNotification::class,
        );
    }
}
