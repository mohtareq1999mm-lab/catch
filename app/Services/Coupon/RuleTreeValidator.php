<?php

namespace App\Services\Coupon;

use App\Enums\EligibilityRuleType;

/**
 * Validates admin-supplied rule_tree structures fail-closed.
 *
 * Accepted shapes:
 * - null (no rules = always eligible in dynamic mode)
 * - Leaf: ['type' => <whitelisted>, 'value' => mixed]
 * - Group: ['operator' => AND|OR, 'rules' => [node, ...]] (nested, max depth 10)
 *
 * Unknown rule/operator, malformed nodes, empty groups, and excessive depth
 * are invalid. Runtime evaluation mirrors these rules (EligibilityEngine).
 */
final class RuleTreeValidator
{
    // Public so the rules-metadata catalog reports the authoritative limit
    // from a single source (grammar itself unchanged).
    public const MAX_DEPTH = 10;

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(?array $tree): array
    {
        if ($tree === null) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        self::validateNode($tree, 0, $errors);

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private static function validateNode(mixed $node, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'Rule tree exceeds maximum depth of '.self::MAX_DEPTH.'.';

            return;
        }

        if (!is_array($node)) {
            $errors[] = 'Each rule node must be an object.';

            return;
        }

        if (isset($node['type'])) {
            if (!is_string($node['type']) || EligibilityRuleType::tryFrom($node['type']) === null) {
                $errors[] = 'Unknown rule type: '.(string) ($node['type'] ?? 'null').'.';
            }

            if (isset($node['operator']) || isset($node['rules'])) {
                $errors[] = 'Leaf rule must not contain operator/rules.';
            }

            self::validateValue($node['type'] ?? null, $node['value'] ?? null, $errors);

            return;
        }

        if (isset($node['operator']) || isset($node['rules'])) {
            $operator = $node['operator'] ?? null;
            $rules = $node['rules'] ?? null;

            if ($operator !== 'AND' && $operator !== 'OR') {
                $errors[] = 'Unknown operator: '.(string) $operator.'. Expected AND or OR.';
            }

            if (!is_array($rules) || empty($rules)) {
                $errors[] = 'Rule group must contain a non-empty rules array (fail-closed).';

                return;
            }

            foreach ($rules as $child) {
                self::validateNode($child, $depth + 1, $errors);
            }

            return;
        }

        $errors[] = 'Rule node must have either type or operator+rules.';
    }

    private static function validateValue(?string $type, mixed $value, array &$errors): void
    {
        $rule = is_string($type) ? EligibilityRuleType::tryFrom($type) : null;
        if ($rule === null) {
            return;
        }

        switch ($rule) {
            case EligibilityRuleType::MIN_COMPLETED_ORDERS:
            case EligibilityRuleType::MAX_COMPLETED_ORDERS:
            case EligibilityRuleType::MIN_COUPONS_USED:
            case EligibilityRuleType::MAX_COUPONS_USED:
                if (!is_numeric($value) || (int) $value < 0) {
                    $errors[] = "Rule {$type} requires a numeric value >= 0.";
                }
                break;
            case EligibilityRuleType::MIN_TOTAL_SPEND:
            case EligibilityRuleType::MAX_TOTAL_SPEND:
                if (!is_numeric($value) || (float) $value < 0) {
                    $errors[] = "Rule {$type} requires a numeric value >= 0.";
                }
                break;
            case EligibilityRuleType::FIRST_ORDER_AFTER:
            case EligibilityRuleType::FIRST_ORDER_BEFORE:
            case EligibilityRuleType::LAST_ORDER_AFTER:
            case EligibilityRuleType::LAST_ORDER_BEFORE:
                if (!is_string($value) || strtotime($value) === false) {
                    $errors[] = "Rule {$type} requires a parseable datetime string.";
                }
                break;
            case EligibilityRuleType::NOT_CLAIMED:
            case EligibilityRuleType::CLAIMED:
            case EligibilityRuleType::HAS_ASSIGNMENT:
                // No value required; ignore any supplied value.
                break;
            case EligibilityRuleType::AREA_IN:
                // Single id or non-empty list of ids (fail-closed on empty/malformed).
                // Strict integers only: floats and decimal strings must NOT
                // silently truncate to a wrong governorate id.
                $ids = is_array($value) ? $value : [$value];
                if (empty($ids)) {
                    $errors[] = "Rule {$type} requires at least one area id.";
                    break;
                }
                foreach ($ids as $id) {
                    if (!self::isStrictPositiveInt($id)) {
                        $errors[] = "Rule {$type} requires positive integer area id(s).";
                        break;
                    }
                }
                break;
            case EligibilityRuleType::HAS_EMAIL:
                // Optional boolean (null/true = require email, false = require no email).
                if ($value !== null && !is_bool($value)) {
                    $errors[] = "Rule {$type} requires a boolean value or null.";
                }
                break;
            case EligibilityRuleType::REGISTERED_AFTER:
            case EligibilityRuleType::REGISTERED_BEFORE:
                if (!is_string($value) || strtotime($value) === false) {
                    $errors[] = "Rule {$type} requires a parseable datetime string.";
                }
                break;
        }
    }

    /**
     * Strict positive-int check: int > 0 or all-digit string.
     * Rejects floats, decimal strings, negatives, zero, bools, null —
     * (int) coercion must never map a malformed value onto a real id.
     */
    private static function isStrictPositiveInt(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }

        return is_string($id) && ctype_digit($id) && (int) $id > 0;
    }
}
