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

class FaqResourceTest extends TestCase
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
    public function index_returns_paginated_response(): void
    {
        Faqs::create(['faq_title' => ['en' => 'FAQ'], 'faq_description' => ['en' => 'Desc']]);

        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [],
                'current_page',
                'from',
                'to',
                'last_page',
                'per_page',
                'total']]);
    }

    /** @test */
    public function index_response_contains_expected_fields(): void
    {
        Faqs::create(['faq_title' => ['en' => 'Field Test'], 'faq_description' => ['en' => 'Field description']]);

        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertOk();
        $item = $response->json('data.data.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('faq_title', $item);
        $this->assertArrayHasKey('faq_description', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('order', $item);
    }

    /** @test */
    public function index_response_returns_translated_string_not_raw_json(): void
    {
        Faqs::create(['faq_title' => ['en' => 'Readable Title'], 'faq_description' => ['en' => 'Readable description']]);

        $response = $this->getJson(self::PREFIX . '/faqs');

        $title = $response->json('data.data.0.faq_title');
        $this->assertIsString($title);
        $this->assertEquals('Readable Title', $title);
    }

    /** @test */
    public function show_response_includes_id_title_and_description(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Show Item'], 'faq_description' => ['en' => 'Show description']]);

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'faq_title',
                'faq_description',
                'status',
                'order']]);
    }

    /** @test */
    public function show_response_does_not_include_internal_fields(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Clean'], 'faq_description' => ['en' => 'Clean desc']]);

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");

        $data = $response->json('data');
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
    }

    /** @test */
    public function response_has_correct_envelope(): void
    {
        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertJsonStructure([
            'status',
            'message',
            'success']);
        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function response_type_is_json(): void
    {
        $response = $this->getJson(self::PREFIX . '/faqs');

        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }
}
