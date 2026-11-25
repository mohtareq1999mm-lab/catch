<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Support\FinanceAuthority;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Role;
use Marvel\Enums\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #187: finance authority is granular and explicitly assigned. Broad role
 * synchronization never turns an unrelated role into a finance actor, and a
 * finance permission never carries payment mutation authority.
 */
class FinanceAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @test */
    public function broad_roles_are_not_automatic_finance_actors(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach ([
            'owner',
            'staff',
            'editor',
            'warehouse-manager',
            'picker',
            'packer',
            'warehouse-supervisor',
        ] as $roleName) {
            $role = Role::findByName($roleName, 'api');

            foreach (FinanceAuthority::permissions() as $permission) {
                $this->assertFalse(
                    $role->hasPermissionTo($permission),
                    "[{$roleName}] must not receive finance permission [{$permission}] automatically."
                );
            }
        }
    }

    /** @test */
    public function finance_permissions_are_granular_and_never_mutate_payment_status(): void
    {
        foreach (FinanceAuthority::permissions() as $permission) {
            $this->assertStringStartsWith('cod-', $permission);
            $this->assertStringStartsNotWith('payments.', $permission);
        }

        $this->assertNotContains(Permission::PAYMENTS_MARK_PAID, FinanceAuthority::permissions());
        $this->assertNotContains(Permission::PAYMENTS_VERIFY, FinanceAuthority::permissions());
        $this->assertNotContains(Permission::PAYMENTS_RECONCILE, FinanceAuthority::permissions());
    }

    /** @test */
    public function broad_seeding_excludes_finance_permissions(): void
    {
        $this->seed(PermissionSeeder::class);

        $broadNames = FinanceAuthority::broadlyGrantablePermissions()->pluck('name')->all();

        $this->assertSame([], array_values(array_intersect($broadNames, FinanceAuthority::permissions())));
    }
}
