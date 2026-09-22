<?php

namespace App\Services\Coupon\Eligibility;

use App\DTOs\Coupon\EligibilityResult;
use App\Enums\EligibilityRuleType;
use App\Services\Customer\CustomerMetricsService;
use Carbon\Carbon;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Governorate;
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
     * Supports: assignment, dynamic, assignment_and_dynamic, assignment_or_dynamic.
     * Rule trees support AND/OR/nested groups recursively (max depth 10).
     *
     * Evaluation context (optional, never trusted for identity):
     * - 'governorate_id': checkout delivery area. Key ABSENT (claim/apply
     *   without area input) defers area_in (passes, revalidated at checkout).
     *   Key PRESENT (checkout/payment, may be null) evaluates strictly:
     *   null/unknown/inactive governorate fails closed.
     *
     * Lock ordering (F-13): read-only; callers hold Targeting FOR UPDATE where needed.
     * Global order: Transaction → Order → Cart → Coupon → Targeting → Assignment → Reservation → Claim → Usage.
     */
    public function evaluate(Coupon $coupon, User $user, array $context = []): EligibilityResult
    {
        $targeting = $coupon->targeting;

        // No targeting = always eligible (backward compatibility)
        if (!$targeting) {
            return EligibilityResult::eligible(
                passedRules: ['no_targeting'],
                evaluatedMetrics: [],
            );
        }

        $mode = $targeting->mode ?? 'assignment';

        // Assignment mode: check whitelist
        if ($mode === 'assignment') {
            return $this->evaluateAssignmentMode($coupon, $user);
        }

        // Dynamic mode: evaluate rule tree
        if ($mode === 'dynamic') {
            return $this->evaluateDynamicMode($coupon, $user, $targeting->rule_tree, $context);
        }

        // Combined modes (DB ENUM extension 2026_09_14_000003)
        if ($mode === 'assignment_and_dynamic' || $mode === 'assignment_or_dynamic') {
            return $this->evaluateCombinedMode($coupon, $user, $targeting, $mode, $context);
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

    private function evaluateDynamicMode(Coupon $coupon, User $user, ?array $ruleTree, array $context = []): EligibilityResult
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
            // Provenance for the new identity rules (no PII: booleans + own timestamps).
            'has_email' => self::hasStrictEmail($user),
            'registered_at' => $user->created_at?->toIso8601String(),
            'governorate_id' => $context['governorate_id'] ?? null,
        ];

        $node = $this->evaluateNode($ruleTree, $metrics, $coupon, $user, 0, $context);

        if ($node['error'] !== null) {
            return EligibilityResult::ineligible(
                passedRules: [],
                failedRules: [['type' => $node['error'], 'reason' => $node['reason']]],
                evaluatedMetrics: $evaluatedMetrics,
            );
        }

        if ($node['passed']) {
            return EligibilityResult::eligible($node['passedRules'], $evaluatedMetrics);
        }

        return EligibilityResult::ineligible($node['passedRules'], $node['failedRules'], $evaluatedMetrics);
    }

    /**
     * Combined assignment+dynamic modes.
     * - assignment_and_dynamic: must have assignment AND pass dynamic tree.
     * - assignment_or_dynamic: must have assignment OR pass dynamic tree.
     * Fail-closed on either branch error.
     */
    private function evaluateCombinedMode(Coupon $coupon, User $user, $targeting, string $mode, array $context = []): EligibilityResult
    {
        $assignmentResult = $this->evaluateAssignmentMode($coupon, $user);
        $dynamicResult = $this->evaluateDynamicMode($coupon, $user, $targeting->rule_tree, $context);

        if ($mode === 'assignment_and_dynamic') {
            if ($assignmentResult->isEligible && $dynamicResult->isEligible) {
                return EligibilityResult::eligible(
                    array_merge($assignmentResult->passedRules, $dynamicResult->passedRules),
                    $dynamicResult->evaluatedMetrics,
                );
            }

            return EligibilityResult::ineligible(
                [],
                array_merge($assignmentResult->failedRules, $dynamicResult->failedRules),
                $dynamicResult->evaluatedMetrics,
            );
        }

        // assignment_or_dynamic
        if ($assignmentResult->isEligible || $dynamicResult->isEligible) {
            $passed = [];
            $metrics = $dynamicResult->evaluatedMetrics;
            if ($assignmentResult->isEligible) {
                $passed = array_merge($passed, $assignmentResult->passedRules);
            }
            if ($dynamicResult->isEligible) {
                $passed = array_merge($passed, $dynamicResult->passedRules);
            }

            return EligibilityResult::eligible($passed, $metrics);
        }

        return EligibilityResult::ineligible(
            [],
            array_merge($assignmentResult->failedRules, $dynamicResult->failedRules),
            $dynamicResult->evaluatedMetrics,
        );
    }

    /**
     * Recursively evaluate a rule node.
     * Node shapes:
     * - Leaf: ['type' => <whitelisted>, 'value' => mixed]
     * - Group: ['operator' => AND|OR, 'rules' => [node, ...]]
     * Returns ['passed'=>bool,'passedRules'=>[],'failedRules'=>[],'error'=>?,'reason'=>?].
     * Fail-closed: unknown operator/rule, malformed tree, depth>10, empty rules array.
     */
    private function evaluateNode(array $node, CustomerMetrics $metrics, Coupon $coupon, User $user, int $depth, array $context = []): array
    {
        if ($depth > 10) {
            return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'max_depth_exceeded', 'reason' => 'Rule tree too deep'];
        }

        // Leaf rule
        if (isset($node['type'])) {
            if (!is_string($node['type'])) {
                return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'malformed_rule', 'reason' => 'Rule type must be string'];
            }
            $result = $this->evaluateRule($node, $metrics, $coupon, $user, $context);
            if ($result['passed']) {
                return ['passed' => true, 'passedRules' => [$result], 'failedRules' => [], 'error' => null, 'reason' => null];
            }

            return ['passed' => false, 'passedRules' => [], 'failedRules' => [$result], 'error' => null, 'reason' => null];
        }

        // Group node
        if (isset($node['operator']) || isset($node['rules'])) {
            $operator = $node['operator'] ?? null;
            $rules = $node['rules'] ?? null;

            if ($operator !== 'AND' && $operator !== 'OR') {
                return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'unknown_operator', 'reason' => 'Unknown operator: '.(string) $operator];
            }

            if (!is_array($rules) || empty($rules)) {
                return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'empty_group', 'reason' => 'Empty rule group fails closed'];
            }

            $passedRules = [];
            $failedRules = [];
            $childOutcomes = [];

            foreach ($rules as $child) {
                if (!is_array($child)) {
                    return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'malformed_rule', 'reason' => 'Rule node must be array'];
                }
                $childResult = $this->evaluateNode($child, $metrics, $coupon, $user, $depth + 1, $context);
                if ($childResult['error'] !== null) {
                    return $childResult;
                }
                $childOutcomes[] = $childResult['passed'];
                $passedRules = array_merge($passedRules, $childResult['passedRules']);
                $failedRules = array_merge($failedRules, $childResult['failedRules']);
            }

            // Group decision uses child outcomes (not leaf counts) so nested
            // partial passes cannot leak through an OR gate.
            if ($operator === 'AND') {
                $passed = !in_array(false, $childOutcomes, true);
            } else {
                $passed = in_array(true, $childOutcomes, true);
            }

            return ['passed' => $passed, 'passedRules' => $passedRules, 'failedRules' => $failedRules, 'error' => null, 'reason' => null];
        }

        return ['passed' => false, 'passedRules' => [], 'failedRules' => [], 'error' => 'malformed_rule', 'reason' => 'Rule node must have type or operator+rules'];
    }

    /**
     * Evaluate a single rule.
     * Returns ['passed' => bool, 'type' => string, 'value' => mixed, 'reason' => string|null]
     *
     * Security: Whitelist-only. Unknown rule types are rejected (fail-closed).
     */
    private function evaluateRule(array $rule, CustomerMetrics $metrics, Coupon $coupon, User $user, array $context = []): array
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
            EligibilityRuleType::AREA_IN => $this->evalAreaIn($value, $context),
            EligibilityRuleType::HAS_EMAIL => $this->evalHasEmail($user, $value),
            EligibilityRuleType::REGISTERED_AFTER => $this->evalRegisteredAfter($user, $value),
            EligibilityRuleType::REGISTERED_BEFORE => $this->evalRegisteredBefore($user, $value),
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
        // F-16 aligned: REDEEMED counts as claimed (blocks), ACTIVE unexpired
        // blocks, EXPIRED/time-expired releases. Matches CouponClaimService.
        $hasActiveClaim = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->exists();

        $hasRedeemed = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', \App\Enums\CouponClaimStatus::REDEEMED)
            ->exists();

        $blocked = $hasActiveClaim || $hasRedeemed;
        $passed = !$blocked;

        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::NOT_CLAIMED->value,
            'value' => null,
            'actual' => $blocked,
            'reason' => $passed ? null : ($hasRedeemed ? 'User already redeemed this coupon' : 'User has an active claim for this coupon'),
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

    /**
     * Strict positive-int check: int > 0 or all-digit string.
     * Mirrors RuleTreeValidator (both must reject the same inputs).
     */
    private static function isStrictPositiveInt(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }

        return is_string($id) && ctype_digit($id) && (int) $id > 0;
    }

    /**
     * Canonical strict email presence (matches the assignment-mail gate).
     * Verification state is NOT considered: presence-only by business decision.
     */
    public static function hasStrictEmail(User $user): bool
    {
        $email = $user->email;

        return is_string($email)
            && trim($email) !== ''
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Area rule. Canonical source: checkout delivery governorate
     * (orders.governorate_id → governorates.id, active only).
     *
     * - Rule value: single id or list (normalized; empty/malformed fails closed).
     * - Context key ABSENT (claim/apply without area input): deferred PASS —
     *   authoritative enforcement happens at checkout/payment with the
     *   delivery area. Recorded in the result reason for snapshot provenance.
     * - Context key PRESENT (checkout/payment, may be null): strict —
     *   null/unknown/inactive/outside-list fails closed.
     */
    private function evalAreaIn($value, array $context): array
    {
        $ids = is_array($value) ? $value : [$value];
        $allowed = [];
        foreach ($ids as $id) {
            // Strict positive ints only — never coerce 1.5/'1.5' onto id 1.
            if (!self::isStrictPositiveInt($id)) {
                return [
                    'passed' => false,
                    'type' => EligibilityRuleType::AREA_IN->value,
                    'value' => $value,
                    'actual' => null,
                    'reason' => 'Malformed area_in rule value',
                ];
            }
            $allowed[] = (int) $id;
        }
        $allowed = array_values(array_unique($allowed));

        if (empty($allowed)) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::AREA_IN->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'Empty area_in rule value fails closed',
            ];
        }

        if (!array_key_exists('governorate_id', $context)) {
            return [
                'passed' => true,
                'type' => EligibilityRuleType::AREA_IN->value,
                'value' => $allowed,
                'actual' => null,
                'reason' => 'deferred: no delivery area in context, enforced at checkout',
            ];
        }

        $governorateId = $context['governorate_id'];
        if (!self::isStrictPositiveInt($governorateId)) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::AREA_IN->value,
                'value' => $allowed,
                'actual' => $governorateId,
                'reason' => 'No delivery area provided for an area-targeted coupon',
            ];
        }
        $governorateId = (int) $governorateId;

        $active = Governorate::query()
            ->whereKey($governorateId)
            ->where('status', true)
            ->exists();

        if (!$active) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::AREA_IN->value,
                'value' => $allowed,
                'actual' => $governorateId,
                'reason' => 'Unknown or inactive delivery area',
            ];
        }

        $passed = in_array($governorateId, $allowed, true);

        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::AREA_IN->value,
            'value' => $allowed,
            'actual' => $governorateId,
            'reason' => $passed ? null : "Delivery area {$governorateId} is not in the allowed list",
        ];
    }

    /**
     * Email presence rule. Value null/true = require email, false = require
     * no email (inverse). Deterministic strict meaning; verification ignored.
     */
    private function evalHasEmail(User $user, $value): array
    {
        if ($value !== null && !is_bool($value)) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::HAS_EMAIL->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'Malformed has_email rule value (boolean or null required)',
            ];
        }

        $hasEmail = self::hasStrictEmail($user);
        $requireEmail = $value !== false;
        $passed = $requireEmail ? $hasEmail : !$hasEmail;

        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::HAS_EMAIL->value,
            'value' => $value,
            'actual' => $hasEmail,
            'reason' => $passed ? null : ($requireEmail ? 'User account has no email' : 'User account has an email'),
        ];
    }

    /**
     * Registration-date rules on users.created_at (UTC datetime).
     * Exclusive boundary (isAfter/isBefore), consistent with the existing
     * first_/last_order_after/before rules. Null created_at fails closed.
     */
    private function evalRegisteredAfter(User $user, $value): array
    {
        if (!$user->created_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::REGISTERED_AFTER->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User account has no creation timestamp',
            ];
        }

        try {
            // Explicit UTC: the app canonical timezone (config/app.php) and
            // the exclusive-boundary guarantee must not depend on php.ini.
            $cutoff = Carbon::parse($value, 'UTC');
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::REGISTERED_AFTER->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'Unparseable registered_after cutoff fails closed',
            ];
        }

        $passed = $user->created_at->isAfter($cutoff);

        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::REGISTERED_AFTER->value,
            'value' => $value,
            'actual' => $user->created_at->toIso8601String(),
            'reason' => $passed ? null : "Account created at {$user->created_at->toDateTimeString()}, must be after {$cutoff->toDateTimeString()}",
        ];
    }

    private function evalRegisteredBefore(User $user, $value): array
    {
        if (!$user->created_at) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::REGISTERED_BEFORE->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'User account has no creation timestamp',
            ];
        }

        try {
            // Explicit UTC: the app canonical timezone (config/app.php) and
            // the exclusive-boundary guarantee must not depend on php.ini.
            $cutoff = Carbon::parse($value, 'UTC');
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'type' => EligibilityRuleType::REGISTERED_BEFORE->value,
                'value' => $value,
                'actual' => null,
                'reason' => 'Unparseable registered_before cutoff fails closed',
            ];
        }

        $passed = $user->created_at->isBefore($cutoff);

        return [
            'passed' => $passed,
            'type' => EligibilityRuleType::REGISTERED_BEFORE->value,
            'value' => $value,
            'actual' => $user->created_at->toIso8601String(),
            'reason' => $passed ? null : "Account created at {$user->created_at->toDateTimeString()}, must be before {$cutoff->toDateTimeString()}",
        ];
    }
}
