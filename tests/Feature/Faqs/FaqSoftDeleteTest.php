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

class FaqSoftDeleteTest extends TestCase
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
    public function faq_uses_soft_deletes_trait(): void
    {
        $uses = class_uses_recursive(Faqs::class);
        $this->assertTrue(in_array('Illuminate\Database\Eloquent\SoftDeletes', $uses));
    }

    /** @test */
    public function deleted_faq_not_in_index(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Gone'], 'faq_description' => ['en' => 'Gone desc']]);
        $faq->delete();

        $response = $this->getJson(self::PREFIX . '/faqs');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertNotContains($faq->id, $ids);
    }

    /** @test */
    public function show_returns_404_for_soft_deleted_faq(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Hidden'], 'faq_description' => ['en' => 'Hidden desc']]);
        $faq->delete();

        $response = $this->getJson(self::PREFIX . "/faqs/{$faq->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function force_delete_removes_permanently(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Permanent'], 'faq_description' => ['en' => 'Permanent desc']]);
        $faqId = $faq->id;
        $faq->forceDelete();

        $this->assertDatabaseMissing('faqs', ['id' => $faqId]);
    }

    /** @test */
    public function multiple_soft_deletes_work(): void
    {
        $faq = Faqs::create(['faq_title' => ['en' => 'Multi'], 'faq_description' => ['en' => 'Multi desc']]);
        $faq->delete();
        $this->assertSoftDeleted($faq);

        $faq->restore();
        $this->assertDatabaseHas('faqs', ['id' => $faq->id]);

        $faq->delete();
        $this->assertSoftDeleted($faq);
    }
}
