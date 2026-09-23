<?php

namespace App\Services\Coupon\Distribution;

/**
 * Deterministic targeting-version hash.
 *
 * Canonicalization: recursive key sort, integer normalization (numeric
 * strings → int), datetime normalization (any parseable date → UTC
 * Y-m-d H:i:s), then SHA-256 over compact JSON. Raw json_encode of user
 * input is FORBIDDEN — key order and int/string drift would fork versions.
 */
final class TreeHash
{
    public static function forRuleTree(?array $ruleTree, string $mode): string
    {
        return hash('sha256', json_encode([
            'mode' => strtolower(trim($mode)),
            'tree' => self::canonicalize($ruleTree),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function canonicalize($value)
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'canonicalize'], $value);
            }

            ksort($value);

            $out = [];

            foreach ($value as $k => $v) {
                $out[(string) $k] = self::canonicalize($v);
            }

            return $out;
        }

        if (is_bool($value) || $value === null) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (is_numeric($trimmed) && preg_match('/^-?\d+$/', $trimmed)) {
                return (int) $trimmed;
            }

            if (is_numeric($trimmed)) {
                return (float) $trimmed;
            }

            // Datetime normalization: any parseable date → UTC canonical.
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $trimmed)) {
                try {
                    return \Carbon\Carbon::parse($trimmed)->utc()->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    return strtolower($trimmed);
                }
            }

            return strtolower($trimmed);
        }

        return $value;
    }
}
