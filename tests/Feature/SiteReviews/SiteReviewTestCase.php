<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class SiteReviewTestCase extends TestCase
{
    use RefreshDatabase;

    protected const GUARD = 'api';
    protected const PREFIX = '/api/v1';
    protected const GENERAL_PREFIX = '/api/v1/general';

    private const SITE_REVIEW_PERMISSIONS = [
        'view-site-reviews',
        'approve-site-reviews',
        'reject-site-reviews',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    protected function createCustomer(): User
    {
        $user = User::create([
            'name' => 'Test Customer',
            'email' => 'customer-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);

        return $user;
    }

    protected function createAuthenticatedCustomer(): User
    {
        $user = $this->createCustomer();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function createAdmin(): User
    {
        return $this->createUserWithPermissions(self::SITE_REVIEW_PERMISSIONS, 'admin');
    }

    protected function createAuthenticatedAdmin(): User
    {
        $user = $this->createAdmin();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function createUserWithPermissions(array $permissions, string $type = 'user'): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN . '-' . uniqid(),
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Test Role']),
        ]);

        $role->givePermissionTo($permissions);

        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type,
        ]);

        $user->assignRole($role);

        return $user;
    }

    protected function createReviewPayload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 5,
            'title' => 'Excellent Website',
            'comment' => 'The website is easy to use and the experience is excellent.',
        ], $overrides);
    }
}
