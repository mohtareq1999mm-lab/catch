<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\TreeHash;
use Tests\TestCase;

class TreeHashTest extends TestCase
{
    public function test_deterministic_for_same_tree()
    {
        $tree = ['operator' => 'AND', 'rules' => [
            ['type' => 'min_completed_orders', 'value' => 3],
            ['type' => 'area_in', 'value' => [5, 9]],
        ]];

        $this->assertSame(TreeHash::forRuleTree($tree, 'dynamic'), TreeHash::forRuleTree($tree, 'dynamic'));
    }

    public function test_key_order_does_not_change_hash()
    {
        $a = ['operator' => 'AND', 'rules' => [['value' => 3, 'type' => 'min_completed_orders']]];
        $b = ['rules' => [['type' => 'min_completed_orders', 'value' => 3]], 'operator' => 'AND'];

        $this->assertSame(TreeHash::forRuleTree($a, 'dynamic'), TreeHash::forRuleTree($b, 'dynamic'));
    }

    public function test_int_string_numeric_values_normalize_together()
    {
        $a = ['type' => 'min_completed_orders', 'value' => 3];
        $b = ['type' => 'min_completed_orders', 'value' => '3'];

        $this->assertSame(TreeHash::forRuleTree($a, 'dynamic'), TreeHash::forRuleTree($b, 'dynamic'));
    }

    public function test_different_values_change_hash()
    {
        $a = ['type' => 'min_completed_orders', 'value' => 3];
        $b = ['type' => 'min_completed_orders', 'value' => 4];

        $this->assertNotSame(TreeHash::forRuleTree($a, 'dynamic'), TreeHash::forRuleTree($b, 'dynamic'));
    }

    public function test_mode_changes_hash()
    {
        $tree = ['type' => 'has_email', 'value' => true];

        $this->assertNotSame(
            TreeHash::forRuleTree($tree, 'dynamic'),
            TreeHash::forRuleTree($tree, 'assignment_and_dynamic')
        );
    }

    public function test_hash_is_sha256_hex()
    {
        $hash = TreeHash::forRuleTree(['type' => 'has_email', 'value' => true], 'dynamic');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
