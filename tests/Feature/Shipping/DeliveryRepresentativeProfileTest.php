<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\DeliveryRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryRepresentativeProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_profile_can_exist_without_user_link(): void
    {
        $rep = DeliveryRepresentative::query()->create([
            'name' => 'Rep One',
            'phone' => '0500000001',
            'email' => 'rep1@example.test',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('delivery_representatives', [
            'id' => $rep->id,
            'name' => 'Rep One',
            'user_id' => null,
            'is_active' => true,
        ]);
        $this->assertNull($rep->user_id);
    }

    public function test_representative_profile_with_optional_user_link_grants_no_staff_warehouse_or_admin_permissions(): void
    {
        Role::findOrCreate('super_admin', 'api');
        Role::findOrCreate('staff', 'api');
        Role::findOrCreate('store_owner', 'api');

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $rep = DeliveryRepresentative::query()->create([
            'name' => 'Rep Linked',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $user->refresh();

        $this->assertDatabaseHas('delivery_representatives', [
            'id' => $rep->id,
            'user_id' => $user->id,
        ]);
        $this->assertFalse($user->hasRole('super_admin'));
        $this->assertFalse($user->hasRole('staff'));
        $this->assertFalse($user->hasRole('store_owner'));
        $this->assertCount(0, $user->roles);
        $this->assertCount(0, $user->getAllPermissions());
        $this->assertCount(0, $user->warehouses()->get());
    }
}
