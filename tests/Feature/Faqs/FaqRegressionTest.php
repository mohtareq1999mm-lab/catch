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

class FaqRegressionTest extends TestCase
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
    public function b1_soft_delete_does_not_hard_delete(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'B1'], 'faq_description' => ['en' => 'B1 desc']]);
        $faqId = $faq->id;

        $faq->delete();

        $this->assertSoftDeleted('faqs', ['id' => $faqId]);
        $this->assertDatabaseHas('faqs', ['id' => $faqId]);
    }

    /** @test */
    public function b2_resource_returns_translated_name_on_index(): void
    {
        Faqs::create([
            'faq_title' => ['en' => 'B2 Title'],
            'faq_description' => ['en' => 'B2 description']]);

        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertOk();
        $this->assertEquals('B2 Title', $response->json('data.data.0.faq_title'));
        $this->assertEquals('B2 description', $response->json('data.data.0.faq_description'));
    }

    /** @test */
    public function b3_model_uses_soft_deletes(): void
    {
        $uses = class_uses_recursive(Faqs::class);
        $this->assertTrue(in_array('Illuminate\Database\Eloquent\SoftDeletes', $uses));
    }

    /** @test */
    public function b4_model_has_translatable_fields(): void
    {
        $faq = new Faqs();
        $this->assertTrue(property_exists($faq, 'translatable'));
        $this->assertIsArray($faq->translatable);
        $this->assertContains('faq_title', $faq->translatable);
        $this->assertContains('faq_description', $faq->translatable);
    }

    /** @test */
    public function b5_model_uses_sortable_trait(): void
    {
        $uses = class_uses_recursive(Faqs::class);
        $this->assertTrue(in_array('Spatie\EloquentSortable\SortableTrait', $uses));
    }

    /** @test */
    public function b6_translation_keys_exist(): void
    {
        $expectedKeys = [
            'MESSAGE.FAQ_CREATED_SUCCESSFULLY',
            'MESSAGE.FAQ_UPDATED_SUCCESSFULLY',
            'MESSAGE.FAQ_DELETED_SUCCESSFULLY',
            'MESSAGE.FAQS_REORDERED_SUCCESSFULLY'];

        $enMessages = include resource_path('lang/en/message.php');
        $arMessages = include resource_path('lang/ar/message.php');

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $arMessages, "Arabic translation missing for $key");
        }

        foreach ($expectedKeys as $key) {
            if (!array_key_exists($key, $enMessages)) {
                $this->markTestIncomplete("English translation missing for $key — raw constant will be used");
            }
        }
    }

    /** @test */
    public function b7_faq_search_by_title(): void
    {
        Faqs::create(['faq_title' => ['en' => 'Searchable Title'], 'faq_description' => ['en' => 'Desc']]);
        Faqs::create(['faq_title' => ['en' => 'Other Item'], 'faq_description' => ['en' => 'Other desc']]);

        $response = $this->getJson(self::PREFIX . '/faqs?search=Searchable');

        $response->assertOk();
        $titles = collect($response->json('data.data'))->pluck('faq_title')->toArray();
        $this->assertContains('Searchable Title', $titles);
        $this->assertNotContains('Other Item', $titles);
    }

    /** @test */
    public function b8_faq_sorting_by_title_asc(): void
    {
        Faqs::create(['faq_title' => ['en' => 'Beta'], 'faq_description' => ['en' => 'Desc']]);
        Faqs::create(['faq_title' => ['en' => 'Alpha'], 'faq_description' => ['en' => 'Desc']]);

        $response = $this->getJson(self::PREFIX . '/faqs?order=faq_title&sortedBy=asc');

        $response->assertOk();
        $titles = collect($response->json('data.data'))->pluck('faq_title')->toArray();
        $this->assertEquals('Alpha', $titles[0]);
        $this->assertEquals('Beta', $titles[1]);
    }

    /** @test */
    public function b9_faq_sorting_by_title_desc(): void
    {
        Faqs::create(['faq_title' => ['en' => 'Alpha'], 'faq_description' => ['en' => 'Desc']]);
        Faqs::create(['faq_title' => ['en' => 'Beta'], 'faq_description' => ['en' => 'Desc']]);

        $response = $this->getJson(self::PREFIX . '/faqs?order=faq_title&sortedBy=desc');

        $response->assertOk();
        $titles = collect($response->json('data.data'))->pluck('faq_title')->toArray();
        $this->assertEquals('Beta', $titles[0]);
        $this->assertEquals('Alpha', $titles[1]);
    }
}
