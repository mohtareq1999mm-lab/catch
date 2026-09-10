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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FaqReorderTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->clearPermissionsCollection();
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
    public function can_reorder_faqs(): void
    {
        $faq1 = Faqs::create(['faq_title' => ['en' => 'First'], 'faq_description' => ['en' => 'First desc']]);
        $faq2 = Faqs::create(['faq_title' => ['en' => 'Second'], 'faq_description' => ['en' => 'Second desc']]);
        $faq3 = Faqs::create(['faq_title' => ['en' => 'Third'], 'faq_description' => ['en' => 'Third desc']]);

        $response = $this->putJson(self::PREFIX . '/faqs/reorder', [
            'faqs' => [$faq3->id, $faq1->id, $faq2->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function reorder_updates_order_column(): void
    {
        $faq1 = Faqs::create(['faq_title' => ['en' => 'A'], 'faq_description' => ['en' => 'A desc']]);
        $faq2 = Faqs::create(['faq_title' => ['en' => 'B'], 'faq_description' => ['en' => 'B desc']]);

        $this->putJson(self::PREFIX . '/faqs/reorder', [
            'faqs' => [$faq2->id, $faq1->id]]);

        $faq1->refresh();
        $faq2->refresh();

        $this->assertLessThan($faq1->order, $faq2->order);
    }

    /** @test */
    public function reorder_validates_faqs_required(): void
    {
        $response = $this->putJson(self::PREFIX . '/faqs/reorder', [
            'faqs' => []]);

        $response->assertStatus(422);
    }
}
