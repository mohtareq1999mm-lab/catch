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

class ContactResourceTest extends TestCase
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
    public function index_returns_paginated_response(): void
    {
        Contact::create(['name' => 'A', 'email' => 'a@test.com', 'subject' => 'S', 'message' => 'M']);

        $response = $this->getJson(self::PREFIX . '/contacts');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [],
                'links' => [
                    'current_page',
                    'from',
                    'to',
                    'last_page',
                    'per_page',
                    'total']]]);
    }

    /** @test */
    public function index_response_contains_expected_fields(): void
    {
        Contact::create(['name' => 'B', 'email' => 'b@test.com', 'subject' => 'S1', 'message' => 'M1']);

        $response = $this->getJson(self::PREFIX . '/contacts');

        $item = $response->json('data.data.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('email', $item);
        $this->assertArrayHasKey('subject', $item);
        $this->assertArrayHasKey('message', $item);
        $this->assertArrayHasKey('is_read', $item);
        $this->assertArrayHasKey('is_replay', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    /** @test */
    public function show_response_has_correct_structure(): void
    {
        $contact = Contact::create([
            'name' => 'C',
            'email' => 'c@test.com',
            'subject' => 'S2',
            'message' => 'M2']);

        $response = $this->getJson(self::PREFIX . "/contacts/{$contact->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'email',
                'subject',
                'message',
                'is_read',
                'is_replay',
                'created_at']]);
    }

    /** @test */
    public function is_read_and_is_replay_are_booleans(): void
    {
        Contact::create(['name' => 'D', 'email' => 'd@test.com', 'subject' => 'S3', 'message' => 'M3']);

        $response = $this->getJson(self::PREFIX . '/contacts');

        $item = $response->json('data.data.0');
        $this->assertIsBool($item['is_read']);
        $this->assertIsBool($item['is_replay']);
    }

    /** @test */
    public function response_has_correct_envelope(): void
    {
        $response = $this->getJson(self::PREFIX . '/contacts');

        $response->assertJsonStructure([
            'status',
            'message',
            'success']);
        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function create_returns_500_due_to_event_type_mismatch(): void
    {
        Notification::fake();

        $response = $this->postJson(self::PREFIX . '/contacts', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'subject' => 'New Inquiry',
            'message' => 'This is a new message.']);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'email',
                'subject',
                'message',
                'is_read',
                'is_replay',
                'created_at']]);
        $response->assertJsonPath('data.is_read', false);
        $response->assertJsonPath('data.is_replay', false);
    }
}
