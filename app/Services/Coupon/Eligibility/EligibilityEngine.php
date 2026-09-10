<?php

namespace App\Services\Coupon\Eligibility;

use App\DTOs\Coupon\EligibilityResult;
use App\Enums\EligibilityRuleType;
use App\Services\Customer\CustomerMetricsService;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;

class EligibilityEngine
{
    public function __construct(
        private readonly CustomerMetricsService $metricsService,
    ) {}

    /**
     * Evaluate eligibility for a user against a coupon's targeting rules.
     *
     * Security: Fail-closed. Unknown rule types are rejected.
     * Phase 1: Exactly 13 whitelisted rules.
     */
    public function evaluate(Coupon $coupon, User $user): EligibilityResult
    {
        $targeting = $coupon->targeting;

        // No targeting = always eligible (backward compatibility)
        if (!$targeting) {
            return EligibilityResult::eligible(
                passedRules: ['no_targeting'],
                evaluatedMetrics: [],
            );
        }

        // Assignment mode: check whitelist
        if ($targeting->mode === 'assignment') {
            return $this->evaluateAssignmentMode($coupon, $user);
        }

        // Dynamic mode: evaluate rule tree
        if ($targeting->mode === 'dynamic') {
            return $this->evaluateDynamicMode($coupon, $user, $targeting->rule_tree);
        }

        // Unknown mode = fail closed
        return EligibilityResult::ineligible(
            passedRules: [],
            failedRules: [['type' => 'unknown_mode', 'reason' => 'Unknown targeting mode']],
            evaluatedMetrics: [],
        );
    }

    private function evaluateAssignmentMode(Coupon $coupon, User $user): EligibilityResult
    {
        $hasAssignment = CouponAssignment::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($hasAssignment) {
            return EligibilityResult::eligible(
                passedRules: [['type' => EligibilityRuleType::HAS_ASSIGNMENT->value]],
                evaluatedMetrics: [],
            );
        }

        return EligibilityResult::ineligible(
            passedRules: [],
            failedRules: [['type' => EligibilityRuleType::HAS_ASSIGNMENT->value, 'reason' => 'No assignment found']],
            evaluatedMetrics: [],
        );
    }

    private function evaluateDynamicMode(Coupon $coupon, User $user, ?array $ruleTree): EligibilityResult
    {
        if (!$ruleTree) {
            return EligibilityResult::eligible(
                passedRules: ['no_rules'],
                evaluatedMetrics: [],
            );
        }

        // Get customer metrics
        $metrics = $this->metricsService->getMetrics($user);

        $evaluatedMetrics = [
            'completed_orders' => $metrics->completed_orders,
            'total_qualifying_order_value' => (float) $metrics->total_qualifying_order_value,
            'first_order_at' => $metrics->first_order_at?->toIso8601String(),
            'last_order_at' => $metrics->last_order_at?->toIso8601String(),
            'coupons_used' => $metrics->coupons_used,
        ];

        // Evaluate rule tree
        $operator = $ruleTree['operator'] ?? 'AND';
        $rules = $ruleTree['rules'] ?? [];

        $passedRules = [];
        $failedRules = [];

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $metrics, $coupon, $user);

            if ($result['passed']) {
                $passedRules[] = $result;
            } else {
                $failedRules[] = $result;
            }
        }

        // Apply operator logic
        if ($operator === 'AND') {
            $isEligible = empty($failedRules);
        } elseif ($operator === 'OR') {
            $isEligible = !empty($passedRules);
        } else {
            // Unknown operator = fail closed
            return EligibilityResult::ineligible(
                passedRules: [],
                failedRules: [['type' => 'unknown_operator', 'reason' => 'Unknown operator: ' . $operator]],
                evaluatedMetrics: $evaluatedMetrics,
            );
        }

        if ($isEligible) {
            return EligibilityResult::eligible($passedRules, $evaluatedMetrics);
        }

        return EligibilityResult::ineligible($passedRules, $failedRules, $evaluatedMetrics);
    }

    /**
     * Evaluate a single rule.
     * Returns ['passed' => bool, 'type' => string, 'value' => mixed, 'reason' => string|null]
     *
     * Security: Whitelist-only. Unknown rule types are rejected (fail-closed).
     */
    private function evaluateRule(array $rule, CustomerMetrics $metrics, Coupon $coupon, User $user): array
    {
        $type = $rule['type'] ?? null;
        $value = $rule['value'] ?? null;

        // Validate type is whitelisted
        $ruleType = $this->validateRuleType($type);
        if (!$ruleType) {
            return [
                'passed' => false,
                'type' => $type ?? 'unknown',
                'value' => $value,
                'reason' => 'Unknown or forbidden rule type',
            ];
        }

        // Execute rule evaluation
        return match ($ruleType) {
            EligibilityRuleType::MIN_COMPLETED_ORDERS => $this->evalMinCompletedOrders($metrics, $value),
            EligibilityRuleType::MAX_COMPLETED_ORDERS => $this->evalMaxCompletedOrders($metrics, $value),
            EligibilityRuleType::MIN_TOTAL_SPEND => $this->evalMinTotalSpend($metrics, $value),
            EligibilityRuleType::MAX_TOTAL_SPEND => $this->evalMaxTotalSpend($metrics, $value),
            EligibilityRuleType::FIRST_ORDER_AFTER => $this->evalFirstOrderAfter($metrics, $value),
            EligibilityRuleType::FIRST_ORDER_BEFORE => $this->evalFirstOrderBefore($metrics, $value),
            EligibilityRuleType::LAST_ORDER_AFTER => $this->evalLastOrderAfter($metrics, $value),
            EligibilityRuleType::LAST_ORDER_BEFORE => $this->evalLastOrderBefore($metrics, $value),
            EligibilityRuleType::MIN_COUPONS_USED => $this->evalMinCouponsUsed($metrics, $value),
            EligibilityRuleType::MAX_COUPONS_USED => $this->evalMaxCouponsUsed($metrics, $value),
            EligibilityRuleType::NOT_CLAIMED => $this->evalNotClaimed($coupon, $user),
            EligibilityRuleType::CLAIMED => $this->evalClaimed($coupon, $user),
            EligibilityRuleType::HAS_ASSIGNMENT => $this->evalHasAssignment($coupon, $user),
        };
    }

    /**
     * Validate rule type against Phase 1 whitelist.
     * Returns EligibilityRuleType enum or null if invalid.
     */
    private function validateRuleType(?string $type): ?EligibilityRuleType
    {
        if (!$type) {
            return null;
        }

        return EligibilityRuleType::tryFrom($type);
    }

    // Rule evaluation methods

    private function evalMinCompletedOrders(CustomerMetrics $metrics, $value): array
    {
        $passed = $metrics->completed_orders >= (int) $value;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MIN_COMPLETED_ORDERS->value,
            'value' => $value,
            'actual' => $metrics->completed_orders,
            'reason' => $passed ? null : "User has {$metrics->completed_orders} orders, needs at least {$value}",
        ];
    }

    private function evalMaxCompletedOrders(CustomerMetrics $metrics, $value): array
    {
        $passed = $metrics->completed_orders <= (int) $value;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MAX_COMPLETED_ORDERS->value,
            'value' => $value,
            'actual' => $metrics->completed_orders,
            'reason' => $passed ? null : "User has {$metrics->completed_orders} orders, max allowed is {$value}",
        ];
    }

    private function evalMinTotalSpend(CustomerMetrics $metrics, $value): array
    {
        $actual = (float) $metrics->total_qualifying_order_value;
        $required = (float) $value;
        $passed = $actual >= $required;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MIN_TOTAL_SPEND->value,
            'value' => $value,
            'actual' => $actual,
            'reason' => $passed ? null : "User spent {$actual}, needs at least {$required}",
        ];
    }

    private function evalMaxTotalSpend(CustomerMetrics $metrics, $value): array
    {
        $actual = (float) $metrics->total_qualifying_order_value;
        $max = (float) $value;
        $passed = $actual <= $max;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MAX_TOTAL_SPEND->value,
            'value' => $value,
            'actual' => $actual,
            'reason' => $passed ? null : "User spent {$actual}, max allowed is {$max}",
        ];
    }

    private function evalFirstOrderAfter(CustomerMetrics $metrics, $value): array
    {
        if (!$metrics->first_order_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::FIRST_ORDER_AFTER->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User has no qualifying orders',
            ];
        }

        $passed = $metrics->first_order_at->isAfter($value);
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::FIRST_ORDER_AFTER->value,
            'value' => $value,
            'actual' => $metrics->first_order_at->toIso8601String(),
            'reason' => $passed ? null : "First order at {$metrics->first_order_at->toDateString()}, must be after {$value}",
        ];
    }

    private function evalFirstOrderBefore(CustomerMetrics $metrics, $value): array
    {
        if (!$metrics->first_order_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::FIRST_ORDER_BEFORE->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User has no qualifying orders',
            ];
        }

        $passed = $metrics->first_order_at->isBefore($value);
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::FIRST_ORDER_BEFORE->value,
            'value' => $value,
            'actual' => $metrics->first_order_at->toIso8601String(),
            'reason' => $passed ? null : "First order at {$metrics->first_order_at->toDateString()}, must be before {$value}",
        ];
    }

    private function evalLastOrderAfter(CustomerMetrics $metrics, $value): array
    {
        if (!$metrics->last_order_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::LAST_ORDER_AFTER->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User has no qualifying orders',
            ];
        }

        $passed = $metrics->last_order_at->isAfter($value);
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::LAST_ORDER_AFTER->value,
            'value' => $value,
            'actual' => $metrics->last_order_at->toIso8601String(),
            'reason' => $passed ? null : "Last order at {$metrics->last_order_at->toDateString()}, must be after {$value}",
        ];
    }

    private function evalLastOrderBefore(CustomerMetrics $metrics, $value): array
    {
        if (!$metrics->last_order_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::LAST_ORDER_BEFORE->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User has no qualifying orders',
            ];
        }

        $passed = $metrics->last_order_at->isBefore($value);
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::LAST_ORDER_BEFORE->value,
            'value' => $value,
            'actual' => $metrics->last_order_at->toIso8601String(),
            'reason' => $passed ? null : "Last order at {$metrics->last_order_at->toDateString()}, must be before {$value}",
        ];
    }

    private function evalMinCouponsUsed(CustomerMetrics $metrics, $value): array
    {
        $passed = $metrics->coupons_used >= (int) $value;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MIN_COUPONS_USED->value,
            'value' => $value,
            'actual' => $metrics->coupons_used,
            'reason' => $passed ? null : "User used {$metrics->coupons_used} coupons, needs at least {$value}",
        ];
    }

    private function evalMaxCouponsUsed(CustomerMetrics $metrics, $value): array
    {
        $passed = $metrics->coupons_used <= (int) $value;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::MAX_COUPONS_USED->value,
            'value' => $value,
            'actual' => $metrics->coupons_used,
            'reason' => $passed ? null : "User used {$metrics->coupons_used} coupons, max allowed is {$value}",
        ];
    }

    private function evalNotClaimed(Coupon $coupon, User $user): array
    {
        $hasClaim = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        $passed = !$hasClaim;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::NOT_CLAIMED->value,
            'value' => null,
            'actual' => $hasClaim,
            'reason' => $passed ? null : 'User has already claimed this coupon',
        ];
    }

    private function evalClaimed(Coupon $coupon, User $user): array
    {
        $hasClaim = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        $passed = $hasClaim;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::CLAIMED->value,
            'value' => null,
            'actual' => $hasClaim,
            'reason' => $passed ? null : 'User has not claimed this coupon',
        ];
    }

    private function evalHasAssignment(Coupon $coupon, User $user): array
    {
        $hasAssignment = CouponAssignment::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        $passed = $hasAssignment;
        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::HAS_ASSIGNMENT->value,
            'value' => null,
            'actual' => $hasAssignment,
            'reason' => $passed ? null : 'User is not assigned to this coupon',
        ];
    }
}
