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

class FaqTranslationTest extends TestCase
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
    public function create_faq_with_multiple_translations(): void
    {
        $response = $this->postJson(self::PREFIX . '/faqs', [
            'faq_title' => [
                'en' => 'How to return?',
                'ar' => 'كيفية الإرجاع؟'],
            'faq_description' => [
                'en' => 'Return policy details.',
                'ar' => 'تفاصيل سياسة الإرجاع.']]);

        $response->assertStatus(201);

        $faq = Faqs::find($response->json('data.id'));
        $this->assertEquals('How to return?', $faq->getTranslation('faq_title', 'en'));
        $this->assertEquals('كيفية الإرجاع؟', $faq->getTranslation('faq_title', 'ar'));
    }

    /** @test */
    public function index_returns_translated_title_in_current_locale(): void
    {
        Faqs::create([
            'faq_title' => ['en' => 'English Title', 'ar' => 'عنوان عربي'],
            'faq_description' => ['en' => 'English desc', 'ar' => 'وصف عربي']]);

        app()->setLocale('en');
        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/faqs');
        $response->assertOk();
        $this->assertEquals('English Title', $response->json('data.data.0.faq_title'));
    }

    /** @test */
    public function index_returns_arabic_translation_when_locale_is_ar(): void
    {
        Faqs::create([
            'faq_title' => ['en' => 'English Title', 'ar' => 'عنوان عربي'],
            'faq_description' => ['en' => 'English desc', 'ar' => 'وصف عربي']]);

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/faqs');
        $response->assertOk();
        $this->assertEquals('عنوان عربي', $response->json('data.data.0.faq_title'));
    }

    /** @test */
    public function show_returns_faq_with_translatable_fields(): void
    {
        $faq = Faqs::create([
            'faq_title' => ['en' => 'Show Title', 'ar' => 'عنوان العرض'],
            'faq_description' => ['en' => 'Show desc', 'ar' => 'وصف العرض']]);

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");
        $response->assertOk();
        $response->assertJsonPath('data.id', $faq->id);
    }

    /** @test */
    public function model_has_translatable_fields_defined(): void
    {
        $faq = new Faqs();
        $this->assertEquals(['faq_title', 'faq_description'], $faq->translatable);
    }
}
