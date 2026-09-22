<?php

namespace App\Support;

/**
 * Canonical coupon-code policy (CP-09, POLICY 6).
 *
 * Canonical form: UPPER(TRIM(code)).
 *
 * - Applied on create / update / lookup / assignment / validation / cart /
 *   checkout / usage. Historical ORDER snapshots are NEVER mutated: orders
 *   store the code as entered, and lookups match case-insensitively so old
 *   rows (e.g. lowercase `coupon_xxxx` auto-codes) keep resolving.
 * - Internal spaces, allowed characters and unicode are preserved as-is;
 *   only surrounding whitespace and case are normalized.
 * - Codes are ASCII by construction (server-generated [A-Z0-9_] and the
 *   admin cannot set arbitrary codes), so PHP mb_strtoupper and SQL
 *   UPPER() agree. Do not issue non-ASCII codes without revisiting this.
 */
final class CouponCode
{
    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim($code);

        if ($trimmed === '') {
            return '';
        }

        return mb_strtoupper($trimmed, 'UTF-8');
    }

    /**
     * Case-insensitive code lookup shared by every coupon entry point.
     * F-07: indexed fast path (exact equality on canonical UPPER).
     * - All persisted codes are canonical UPPER(TRIM) via Coupon::creating/saving.
     * - Exact `code = ?` hits the unique index (no UPPER() full scan).
     * - Pre-canonical lowercase rows MUST be backfilled once:
     *   `UPDATE coupons SET code = UPPER(TRIM(code))` (see 2026_09_27 migration).
     *   Until backfilled, use queryByCodeLegacy() for one-off reconciliation only.
     * - Historical ORDER snapshots are never rewritten; order lookups resolve
     *   via the same canonical match.
     * Blank input matches nothing.
     *
     * Lock ordering (F-13): callers must already hold outer locks in global order
     * Transaction → Order → Cart → Coupon → Targeting → Assignment → Reservation → Claim → Usage.
     */
    public static function queryByCode($query, ?string $code)
    {
        $normalized = self::normalize($code);

        if ($normalized === null || $normalized === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('code', $normalized);
    }

    /**
     * Legacy fallback for pre-canonical rows only. Do NOT use on hot paths;
     * prefer queryByCode(). Kept for one-off reconciliation/backfill.
     */
    public static function queryByCodeLegacy($query, ?string $code)
    {
        $normalized = self::normalize($code);

        if ($normalized === null || $normalized === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('UPPER(code) = ?', [$normalized]);
    }
}
