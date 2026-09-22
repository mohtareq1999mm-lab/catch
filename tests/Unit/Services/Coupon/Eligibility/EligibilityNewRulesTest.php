<?php

namespace Tests\Unit\Services\Coupon\Eligibility;

use App\Enums\EligibilityRuleType;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use App\Services\Coupon\RuleTreeValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * First-class rule coverage: area_in / has_email / registered_after / registered_before.
 *
 * Canonical decisions under test:
 * - area_in source: checkout delivery governorate (orders.governorate_id → governorates.id, active only).
 * - has_email: strict presence (trimmed + RFC-valid); verification state ignored.
 * - registered_*: users.created_at UTC datetime, EXCLUSIVE boundary; null fails closed.
 */
class EligibilityNewRulesTest extends TestCase
{
    use RefreshDatabase;

    private EligibilityEngine $engine;

    private Country $country;

    private Governorate $riyadh;

    private Governorate $jeddah;

    private Governorate $inactive;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->engine = app(EligibilityEngine::class);

        $this->country = Country::create(['name' => 'Testland']);
        $this->riyadh = Governorate::create(['country_id' => $this->country->id, 'name' => 'Riyadh', 'status' => true]);
        $this->jeddah = Governorate::create(['country_id' => $this->country->id, 'name' => 'Jeddah', 'status' => true]);
        $this->inactive = Governorate::create(['country_id' => $this->country->id, 'name' => 'Dead', 'status' => false]);
    }

    private function createCoupon(string $mode, ?array $ruleTree): Coupon
    {
        $code = 'NEWRULE-' . Str::random(8);
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'New Rules Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => $mode,
            'rule_tree' => $ruleTree,
        ]);

        return $coupon->fresh();
    }

    private function evaluate(Coupon $coupon, User $user, array $context = [])
    {
        return $this->engine->evaluate($coupon, $user, $context);
    }

    // =====================================================================
    // area_in
    // =====================================================================

    /** @test */
    public function area_in_defers_without_delivery_context(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);
        $user = User::factory()->create();

        $result = $this->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    /** @test */
    public function area_in_passes_for_allowed_area(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);
        $user = User::factory()->create();

        $result = $this->evaluate($coupon, $user, ['governorate_id' => $this->riyadh->id]);

        $this->assertTrue($result->isEligible);
    }

    /** @test */
    public function area_in_supports_single_id_and_multiple_ids(): void
    {
        $single = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => $this->riyadh->id]);
        $multi = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id, $this->jeddah->id]]);
        $user = User::factory()->create();
        $ctx = ['governorate_id' => $this->jeddah->id];

        $this->assertFalse($this->evaluate($single, $user, $ctx)->isEligible);
        $this->assertTrue($this->evaluate($multi, $user, $ctx)->isEligible);
    }

    /** @test */
    public function area_in_fails_for_outside_area(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);
        $user = User::factory()->create();

        $result = $this->evaluate($coupon, $user, ['governorate_id' => $this->jeddah->id]);

        $this->assertFalse($result->isEligible);
    }

    /** @test */
    public function area_in_fails_closed_for_unknown_inactive_and_null_area(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);
        $user = User::factory()->create();

        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => 999999])->isEligible, 'unknown area');
        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => $this->inactive->id])->isEligible, 'inactive area');
        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => null])->isEligible, 'null area (pickup)');
        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => 'abc'])->isEligible, 'invalid area id');
    }

    /** @test */
    public function area_in_empty_and_malformed_values_fail_closed(): void
    {
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => []])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => ['x']])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => 0])['valid']);

        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => []]);
        $user = User::factory()->create();

        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => $this->riyadh->id])->isEligible);
        $this->assertFalse($this->evaluate($coupon, $user)->isEligible, 'empty list fails even when deferred');
    }

    /** @test */
    public function area_in_combines_with_and_or_and_nesting(): void
    {
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 5]);
        $ctxRiyadh = ['governorate_id' => $this->riyadh->id];
        $ctxJeddah = ['governorate_id' => $this->jeddah->id];

        $and = $this->createCoupon('dynamic', [
            'operator' => 'AND',
            'rules' => [
                ['type' => 'area_in', 'value' => [$this->riyadh->id]],
                ['type' => 'min_completed_orders', 'value' => 5],
            ],
        ]);
        $this->assertTrue($this->evaluate($and, $user, $ctxRiyadh)->isEligible);
        $this->assertFalse($this->evaluate($and, $user, $ctxJeddah)->isEligible);

        $or = $this->createCoupon('dynamic', [
            'operator' => 'OR',
            'rules' => [
                ['type' => 'area_in', 'value' => [$this->riyadh->id]],
                ['type' => 'min_completed_orders', 'value' => 99],
            ],
        ]);
        $this->assertTrue($this->evaluate($or, $user, $ctxRiyadh)->isEligible);
        $this->assertFalse($this->evaluate($or, $user, $ctxJeddah)->isEligible);

        $nested = $this->createCoupon('dynamic', [
            'operator' => 'OR',
            'rules' => [
                [
                    'operator' => 'AND',
                    'rules' => [
                        ['type' => 'area_in', 'value' => [$this->riyadh->id, $this->jeddah->id]],
                        ['type' => 'has_email', 'value' => true],
                    ],
                ],
                [
                    'operator' => 'AND',
                    'rules' => [
                        ['type' => 'min_completed_orders', 'value' => 5],
                        ['type' => 'registered_after', 'value' => '2020-01-01'],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($this->evaluate($nested, $user, $ctxJeddah)->isEligible, 'nested second branch carries');
    }

    // =====================================================================
    // has_email
    // =====================================================================

    /** @test */
    public function has_email_passes_with_email_and_fails_without(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'has_email', 'value' => true]);

        $withEmail = User::factory()->create();
        $withoutEmail = User::factory()->withoutEmail()->create();

        $this->assertTrue($this->evaluate($coupon, $withEmail)->isEligible);
        $this->assertFalse($this->evaluate($coupon, $withoutEmail)->isEligible);
    }

    /** @test */
    public function has_email_treats_empty_and_invalid_email_as_no_email(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'has_email', 'value' => true]);

        $empty = User::factory()->create(['email' => '']);
        $invalid = User::factory()->create(['email' => 'not-an-email']);

        $this->assertFalse($this->evaluate($coupon, $empty)->isEligible, 'empty string');
        $this->assertFalse($this->evaluate($coupon, $invalid)->isEligible, 'invalid format');
    }

    /** @test */
    public function has_email_ignores_verification_state(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'has_email', 'value' => true]);

        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->assertNotNull($unverified->email);
        $this->assertTrue($this->evaluate($coupon, $unverified)->isEligible);
    }

    /** @test */
    public function has_email_false_requires_no_email(): void
    {
        $coupon = $this->createCoupon('dynamic', ['type' => 'has_email', 'value' => false]);

        $withEmail = User::factory()->create();
        $withoutEmail = User::factory()->withoutEmail()->create();

        $this->assertFalse($this->evaluate($coupon, $withEmail)->isEligible);
        $this->assertTrue($this->evaluate($coupon, $withoutEmail)->isEligible);
    }

    /** @test */
    public function has_email_rejects_non_boolean_values(): void
    {
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'has_email', 'value' => 'yes'])['valid']);
        $this->assertTrue(RuleTreeValidator::validate(['type' => 'has_email', 'value' => true])['valid']);
        $this->assertTrue(RuleTreeValidator::validate(['type' => 'has_email'])['valid'], 'null value allowed');

        $coupon = $this->createCoupon('dynamic', ['type' => 'has_email', 'value' => 'yes']);
        $user = User::factory()->create();

        $this->assertFalse($this->evaluate($coupon, $user)->isEligible, 'runtime fail-closed');
    }

    // =====================================================================
    // registered_after / registered_before
    // =====================================================================

    /** @test */
    public function registered_after_boundary_is_exclusive(): void
    {
        $cutoff = '2026-01-01 00:00:00';
        $coupon = $this->createCoupon('dynamic', ['type' => 'registered_after', 'value' => $cutoff]);

        $before = User::factory()->create(['created_at' => Carbon::parse('2025-12-31 23:59:59')]);
        $exact = User::factory()->create(['created_at' => Carbon::parse($cutoff)]);
        $after = User::factory()->create(['created_at' => Carbon::parse('2026-01-01 00:00:01')]);

        $this->assertFalse($this->evaluate($coupon, $before)->isEligible, 'before cutoff fails');
        $this->assertFalse($this->evaluate($coupon, $exact)->isEligible, 'exact cutoff fails (exclusive)');
        $this->assertTrue($this->evaluate($coupon, $after)->isEligible, 'after cutoff passes');
    }

    /** @test */
    public function registered_before_boundary_is_exclusive(): void
    {
        $cutoff = '2026-01-01 00:00:00';
        $coupon = $this->createCoupon('dynamic', ['type' => 'registered_before', 'value' => $cutoff]);

        $before = User::factory()->create(['created_at' => Carbon::parse('2025-12-31 23:59:59')]);
        $exact = User::factory()->create(['created_at' => Carbon::parse($cutoff)]);
        $after = User::factory()->create(['created_at' => Carbon::parse('2026-06-01')]);

        $this->assertTrue($this->evaluate($coupon, $before)->isEligible);
        $this->assertFalse($this->evaluate($coupon, $exact)->isEligible, 'exact cutoff fails (exclusive)');
        $this->assertFalse($this->evaluate($coupon, $after)->isEligible);
    }

    /** @test */
    public function registered_rules_reject_unparseable_cutoffs(): void
    {
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'registered_after', 'value' => 'not-a-date'])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'registered_before', 'value' => ''])['valid']);
        $this->assertTrue(RuleTreeValidator::validate(['type' => 'registered_after', 'value' => '2026-01-01'])['valid']);
    }

    /** @test */
    public function new_rules_combine_with_existing_grammar(): void
    {
        $user = User::factory()->create(['created_at' => Carbon::parse('2026-03-01')]);

        $coupon = $this->createCoupon('dynamic', [
            'operator' => 'AND',
            'rules' => [
                ['type' => 'has_email', 'value' => true],
                ['type' => 'registered_after', 'value' => '2026-01-01'],
            ],
        ]);

        $this->assertTrue($this->evaluate($coupon, $user)->isEligible);

        $noEmail = User::factory()->withoutEmail()->create(['created_at' => Carbon::parse('2026-03-01')]);
        $this->assertFalse($this->evaluate($coupon, $noEmail)->isEligible);
    }

    /** @test */
    public function validator_accepts_new_rule_types_with_valid_values(): void
    {
        $areaTree = ['type' => EligibilityRuleType::AREA_IN->value, 'value' => [$this->riyadh->id]];
        $this->assertTrue(RuleTreeValidator::validate($areaTree)['valid']);

        $emailTree = ['type' => EligibilityRuleType::HAS_EMAIL->value, 'value' => true];
        $this->assertTrue(RuleTreeValidator::validate($emailTree)['valid']);

        $afterTree = ['type' => EligibilityRuleType::REGISTERED_AFTER->value, 'value' => '2026-01-01'];
        $this->assertTrue(RuleTreeValidator::validate($afterTree)['valid']);

        $beforeTree = ['type' => EligibilityRuleType::REGISTERED_BEFORE->value, 'value' => '2026-01-01'];
        $this->assertTrue(RuleTreeValidator::validate($beforeTree)['valid']);
    }

    /** @test */
    public function area_in_rejects_floats_and_decimal_strings_without_coercion(): void
    {
        // 1.5 must NOT truncate onto governorate id 1.
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => 1.5])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => ['1.5']])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => [-2]])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area_in', 'value' => [true]])['valid']);
        $this->assertTrue(RuleTreeValidator::validate(['type' => 'area_in', 'value' => ['2']])['valid'], 'digit string accepted');

        $coupon = $this->createCoupon('dynamic', ['type' => 'area_in', 'value' => [1.5]]);
        $user = User::factory()->create();

        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => $this->riyadh->id])->isEligible);
        $this->assertFalse($this->evaluate($coupon, $user, ['governorate_id' => '1.5'])->isEligible, 'context float fails closed');
    }

    /** @test */
    public function unknown_rules_still_fail_closed(): void
    {
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'area', 'value' => [1]])['valid'], 'area is not a rule; area_in is');
        $this->assertFalse(RuleTreeValidator::validate(['type' => 'registered_at', 'value' => '2026-01-01'])['valid']);
    }
}
