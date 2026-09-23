<?php

namespace App\Services\Coupon\Distribution\Selection;

/**
 * Structural walk of a targeting rule tree.
 *
 * Output preserves AND/OR grouping so the selector can intersect (AND),
 * union (OR), or fall back to broad scanning for shapes it cannot prove
 * safe. Leaves carry {type, value}; groups {operator, rules[]}.
 */
final class RuleFamilyExtractor
{
    /**
     * @return array{operator: string, leaves: list<array{type: string, value: mixed}>, groups: list<array>, parseable: bool}
     */
    public static function extract(?array $tree): array
    {
        if ($tree === null || $tree === []) {
            return ['operator' => 'AND', 'leaves' => [], 'groups' => [], 'parseable' => true];
        }

        if (isset($tree['type'])) {
            return [
                'operator' => 'AND',
                'leaves' => [['type' => (string) $tree['type'], 'value' => $tree['value'] ?? null]],
                'groups' => [],
                'parseable' => true,
            ];
        }

        $operator = strtoupper((string) ($tree['operator'] ?? 'AND'));

        if (! in_array($operator, ['AND', 'OR'], true)) {
            return ['operator' => 'AND', 'leaves' => [], 'groups' => [], 'parseable' => false];
        }

        $rules = $tree['rules'] ?? null;

        if (! is_array($rules) || $rules === []) {
            return ['operator' => 'AND', 'leaves' => [], 'groups' => [], 'parseable' => false];
        }

        $leaves = [];
        $groups = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                return ['operator' => 'AND', 'leaves' => [], 'groups' => [], 'parseable' => false];
            }

            if (isset($rule['type'])) {
                $leaves[] = ['type' => (string) $rule['type'], 'value' => $rule['value'] ?? null];

                continue;
            }

            $sub = self::extract($rule);

            if (! $sub['parseable']) {
                return ['operator' => 'AND', 'leaves' => [], 'groups' => [], 'parseable' => false];
            }

            $groups[] = $sub;
        }

        return ['operator' => $operator, 'leaves' => $leaves, 'groups' => $groups, 'parseable' => true];
    }

    /**
     * @return list<string>
     */
    public static function leafTypes(array $extracted): array
    {
        $types = array_map(static fn ($leaf) => $leaf['type'], $extracted['leaves']);

        foreach ($extracted['groups'] as $group) {
            $types = array_merge($types, self::leafTypes($group));
        }

        return array_values(array_unique($types));
    }
}
