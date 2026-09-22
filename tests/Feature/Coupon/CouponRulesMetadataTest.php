<?php

namespace Tests\Feature\Coupon;

use App\Enums\EligibilityRuleType;
use App\Services\Coupon\CouponRuleMetadata;
use App\Services\Coupon\RuleTreeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponRulesMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Permission::firstOrCreate(['name' => 'view-coupons', 'guard_name' => 'api']);
        $admin->givePermissionTo('view-coupons');

        return $admin;
    }

    public function test_guest_cannot_fetch_rules()
    {
        $this->getJson('/api/v1/coupons/rules')->assertStatus(401);
        $this->getJson('/api/v1/admin/coupons/rules')->assertStatus(401);
    }

    public function test_customer_without_permission_is_forbidden()
    {
        $user = User::factory()->create(['type' => 'user']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/coupons/rules')->assertStatus(403);
    }

    public function test_admin_receives_full_catalog_with_stable_shape()
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/v1/coupons/rules');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'status',
                'message',
                'success',
                'data' => [
                    'rules' => [
                        '*' => [
                            'type',
                            'label' => ['en', 'ar'],
                            'description' => ['en', 'ar'],
                            'value_type',
                            'value_required',
                            'value_example',
                            'min',
                            'max',
                            'allowed_values',
                            'date_format',
                            'context',
                            'evaluation' => ['claim', 'apply', 'checkout', 'fast_checkout', 'defers_without_context'],
                        ],
                    ],
                    'rule_tree' => [
                        'supported',
                        'operators',
                        'max_depth',
                        'nested_groups_allowed',
                        'empty_group_allowed',
                        'null_allowed',
                        'duplicate_rules_allowed',
                        'unknown_rule_behavior',
                        'malformed_node_behavior',
                    ],
                ],
            ]);

        $rules = $response->json('data.rules');
        $this->assertCount(17, $rules);

        // Exactly one entry per runtime-supported rule, identifiers match.
        $types = array_column($rules, 'type');
        sort($types);
        $expected = array_map(fn ($c) => $c->value, EligibilityRuleType::cases());
        sort($expected);
        $this->assertSame($expected, $types);

        // Grammar mirrors the validator.
        $response->assertJsonPath('data.rule_tree.max_depth', RuleTreeValidator::MAX_DEPTH)
            ->assertJsonPath('data.rule_tree.operators', ['AND', 'OR'])
            ->assertJsonPath('data.rule_tree.empty_group_allowed', false)
            ->assertJsonPath('data.rule_tree.null_allowed', true);
    }

    public function test_legacy_alias_serves_identical_catalog()
    {
        Sanctum::actingAs($this->admin());

        $canonical = $this->getJson('/api/v1/coupons/rules')->assertOk()->json('data');
        $legacy = $this->getJson('/api/v1/admin/coupons/rules')->assertOk()->json('data');

        $this->assertSame($canonical, $legacy);
    }

    public function test_every_exposed_rule_passes_rule_tree_validator()
    {
        $samples = [
            'min_completed_orders' => 3,
            'max_completed_orders' => 10,
            'min_total_spend' => '100.00',
            'max_total_spend' => '500.00',
            'first_order_after' => '2024-01-01',
            'first_order_before' => '2025-01-01',
            'last_order_after' => '2024-01-01',
            'last_order_before' => '2025-01-01',
            'min_coupons_used' => 1,
            'max_coupons_used' => 5,
            'claimed' => null,
            'not_claimed' => null,
            'has_assignment' => null,
            'area_in' => [1, 2],
            'has_email' => true,
            'registered_after' => '2024-01-01',
            'registered_before' => '2025-01-01',
        ];

        foreach (CouponRuleMetadata::all() as $meta) {
            $this->assertArrayHasKey($meta['type'], $samples, "No validator sample for {$meta['type']}");
            $check = RuleTreeValidator::validate([
                'operator' => 'AND',
                'rules' => [['type' => $meta['type'], 'value' => $samples[$meta['type']]]],
            ]);
            $this->assertTrue($check['valid'], "Exposed rule {$meta['type']} rejected by validator: ".implode('; ', $check['errors']));
        }
    }

    public function test_response_exposes_no_sensitive_data()
    {
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/v1/coupons/rules')->assertOk()->json();

        $flat = json_encode($body);
        foreach (['eligibility_snapshot', 'limiter', 'coupon_usages', 'assignments', 'user_id', 'password', 'remember_token', 'SELECT', 'class'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $flat, "Leaked needle: {$needle}");
        }
    }
}
